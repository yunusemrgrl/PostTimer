<?php

namespace App\Filament\App\Resources\Contents\Tables;

use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use Carbon\Carbon;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ContentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('surface')
                    ->label('Tür')
                    ->formatStateUsing(fn (Content $record): string => Content::surfaces()[$record->surface]
                        ?? Content::types()[$record->type]
                        ?? $record->type)
                    ->badge()
                    ->color(fn (string $record): string => match ($record) {
                        'REELS' => 'success',
                        'STORY' => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('caption')
                    ->label('Açıklama')
                    ->limit(50)
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('product.title')
                    ->label('Ürün')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(),

                // Basit publication durum özeti (örn. "Yayınlandı: 2 · Zamanlandı: 1")
                TextColumn::make('publications_summary')
                    ->label('Yayınlar')
                    ->state(fn (Content $record): string => $record->publicationsSummary())
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Medya Türü')
                    ->options(Content::types()),

                SelectFilter::make('surface')
                    ->label('Yayın Yüzeyi')
                    ->options(Content::surfaces()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Sil'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    self::bulkDistributeAction(),
                ]),
            ]);
    }

    /**
     * "Seçilenleri Dağıt": Birden fazla Content'i seçilen Instagram
     * hesaplarına dağıtır. Başlangıç tarihine göre her yayına sırayla
     * aralık eklenir (stagger) — böylece aynı hesaba arka arkaya
     * video atılmaz, yayınlar zaman içinde yayılır.
     *
     * Örnek: 3 content × 2 hesap = 6 yayın
     *   HesapA: content1 @ 10:00, content2 @ 10:04, content3 @ 10:08
     *   HesapB: content1 @ 10:00, content2 @ 10:04, content3 @ 10:08
     *
     * interval_minutes her yayın arasındaki dakika farkı.
     */
    private static function bulkDistributeAction(): BulkAction
    {
        return BulkAction::make('bulkDistribute')
            ->label('Seçilenleri Dağıt')
            ->icon('heroicon-m-share')
            ->color('success')
            ->schema([
                CheckboxList::make('account_ids')
                    ->label('Instagram Hesapları')
                    ->options(fn (): array => self::accountOptions())
                    ->columns(2)
                    ->required(),

                DateTimePicker::make('start_at')
                    ->label('Başlangıç zamanı')
                    ->helperText('İlk yayın bu zamanda başlar, sonraki videolar belirtilen aralıkla sırayla yayımlanır.')
                    ->minDate(now()->startOfMinute())
                    ->seconds(false)
                    ->required(),

                TextInput::make('interval_minutes')
                    ->label('Yayın aralığı (dakika)')
                    ->numeric()
                    ->default(240)
                    ->minValue(10)
                    ->helperText('Her yayın arasındaki dakika farkı. Örn: 240 = 4 saat ara.')
                    ->required(),
            ])
            ->modalHeading('Seçilen İçerikleri Hesaplara Dağıt')
            ->modalDescription('Seçilen tüm içerikler, seçilen her hesap için sırayla zamanlanır. Her yayına belirtilen dakika aralık eklenir.')
            ->action(function (Collection $records, array $data): void {
                $team = Filament::getTenant();

                $accounts = InstagramAccount::query()
                    ->where('team_id', $team->id)
                    ->whereIn('ig_user_id', (array) ($data['account_ids'] ?? []))
                    ->get();

                $startAt = Carbon::parse($data['start_at']);
                $intervalMinutes = (int) ($data['interval_minutes'] ?? 240);

                $created = 0;
                $skipped = 0;
                $slotIndex = 0;

                // İçerikleri oluşturma sırasına göre diz
                $contents = $records->sortBy('id')->values();

                foreach ($contents as $content) {
                    foreach ($accounts as $account) {
                        $exists = Publication::query()
                            ->where('content_id', $content->id)
                            ->where('instagram_account_id', $account->id)
                            ->exists();

                        if ($exists) {
                            $skipped++;

                            continue;
                        }

                        $scheduledAt = $startAt->copy()->addMinutes($slotIndex * $intervalMinutes);

                        Publication::query()->create([
                            'team_id' => $content->team_id,
                            'content_id' => $content->id,
                            'instagram_account_id' => $account->id,
                            'ig_user_id' => $account->ig_user_id,
                            'status' => Publication::STATUS_SCHEDULED,
                            'scheduled_at' => $scheduledAt,
                            'created_by' => auth()->id(),
                        ]);

                        $created++;
                        $slotIndex++;
                    }
                }

                Notification::make()
                    ->title("{$created} yayın oluşturuldu"
                        .($skipped > 0 ? ", {$skipped} zaten mevcuttu" : ''))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function accountOptions(): array
    {
        return Filament::getTenant()
            ?->instagramAccounts()
            ->orderBy('username')
            ->get()
            ->mapWithKeys(fn ($account): array => [$account->ig_user_id => '@'.($account->username ?? 'Bilinmeyen hesap')])
            ->all() ?? [];
    }
}
