<?php

namespace App\Filament\App\Resources\Contents\Pages;

use App\Filament\App\Resources\Contents\ContentResource;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class EditContent extends EditRecord
{
    protected static string $resource = ContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->distributeAction(),

            DeleteAction::make(),
        ];
    }

    /**
     * CreateContent ile aynı editör düzeni; ilişki yöneticileri (yayınlar)
     * grid'in altında kalmaya devam eder.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, '2xl' => 5])
                    ->schema([
                        $this->getFormContentComponent()
                            ->columnSpan(['default' => 1, '2xl' => 3]),

                        View::make('filament.app.components.content-preview')
                            ->columnSpan(['default' => 1, '2xl' => 2]),
                    ]),

                $this->getRelationManagersContentComponent(),
            ]);
    }

    /**
     * "Hesaplara Dağıt": Seçilen Instagram hesapları için Publication
     * oluşturur. Zaten dağıtılmış hesaplar seçilemez ve bildirilir.
     */
    protected function distributeAction(): Action
    {
        return Action::make('distribute')
            ->label('Hesaplara Dağıt')
            ->icon('heroicon-m-share')
            ->color('success')
            ->schema([
                CheckboxList::make('account_ids')
                    ->label('Instagram Hesapları')
                    ->options(fn (): array => $this->availableAccountOptions())
                    ->descriptions(fn (): array => $this->distributedAccountDescriptions())
                    ->columns(2)
                    ->required()
                    ->live(),

                DateTimePicker::make('scheduled_at')
                    ->label('Yayın zamanı')
                    ->helperText('Boş bırakılırsa yayın taslak olarak oluşturulur; sonra "Şimdi yayınla" ile paylaşılır.')
                    ->minDate(now()->startOfMinute())
                    ->seconds(false),
            ])
            ->modalHeading('Hesaplara Dağıt')
            ->modalDescription('Bu içerik seçilen her Instagram hesabı için ayrı bir yayın kaydı olarak oluşturulur.')
            ->action(function (array $data): void {
                $content = $this->getRecord();

                /** @var Collection<int, InstagramAccount> $accounts */
                $accounts = InstagramAccount::query()
                    ->where('team_id', $content->team_id) // tenant-dışı hesaplara asla dağıtılamaz
                    ->whereIn('ig_user_id', (array) ($data['account_ids'] ?? []))
                    ->get();

                $scheduledAt = filled($data['scheduled_at'] ?? null)
                    ? Carbon::parse($data['scheduled_at'])
                    : null;

                $created = 0;
                $skipped = 0;

                foreach ($accounts as $account) {
                    // Unique(content_id, instagram_account_id) constraint'ine
                    // güvenmeden önce açıkça kontrol edilir; mevcutlar atlanır.
                    $exists = Publication::query()
                        ->where('content_id', $content->id)
                        ->where('instagram_account_id', $account->id)
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    Publication::query()->create([
                        'team_id' => $content->team_id,
                        'content_id' => $content->id,
                        'instagram_account_id' => $account->id,
                        'ig_user_id' => $account->ig_user_id,
                        'status' => $scheduledAt?->isFuture()
                            ? Publication::STATUS_SCHEDULED
                            : Publication::STATUS_DRAFT,
                        'scheduled_at' => $scheduledAt,
                        'created_by' => auth()->id(),
                    ]);

                    $created++;
                }

                Notification::make()
                    ->title("{$created} hesaba dağıtıldı".($skipped > 0 ? ", {$skipped} hesap zaten mevcuttu" : ''))
                    ->success()
                    ->send();
            });
    }

    /**
     * Bu content için henüz Publication'ı olmayan hesaplar seçilebilir.
     *
     * @return array<string, string>
     */
    protected function availableAccountOptions(): array
    {
        $distributedIds = $this->getRecord()
            ?->publications()
            ->pluck('instagram_account_id')
            ->all() ?? [];

        return Filament::getTenant()
            ?->instagramAccounts()
            ->whereNotIn('id', $distributedIds)
            ->orderBy('username')
            ->get()
            ->mapWithKeys(fn ($account): array => [$account->ig_user_id => '@'.($account->username ?? 'Bilinmeyen hesap')])
            ->all() ?? [];
    }

    /**
     * Zaten dağıtılmış hesaplar, açıklama olarak kullanıcıya gösterilir.
     *
     * @return array<string, string>
     */
    protected function distributedAccountDescriptions(): array
    {
        return Filament::getTenant()
            ?->instagramAccounts()
            ->whereIn('id', $this->getRecord()?->publications()->pluck('instagram_account_id')->all() ?? [])
            ->get()
            ->mapWithKeys(fn ($account): array => [
                $account->ig_user_id => 'Zaten dağıtılmış',
            ])
            ->all() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Content::resolveCarouselMedia($data);
    }
}
