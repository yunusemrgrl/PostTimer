<?php

namespace App\Filament\App\Resources\Contents\RelationManagers;

use App\Domain\Publishing\Services\PublicationPublishingService;
use App\Jobs\PublishScheduledPublication;
use App\Models\Publication;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * Bir Content'in hesaplara dağıtılmış yayın kayıtları. Publish/iptal/retry
 * aksiyonları mevcut Publication publish motorunu kullanır; eski
 * InstagramPost akışıyla bir ilgisi yoktur.
 */
class PublicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'publications';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('instagramAccount.username')
                    ->label('Hesap')
                    ->formatStateUsing(fn (?string $state): string => $state ? '@'.$state : '—')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Durum')
                    ->formatStateUsing(fn (string $state): string => Publication::statuses()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Publication::STATUS_PUBLISHED => 'success',
                        Publication::STATUS_SCHEDULED => 'info',
                        Publication::STATUS_PUBLISHING => 'warning',
                        Publication::STATUS_FAILED, Publication::STATUS_FLAGGED => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('scheduled_at')
                    ->label('Planlanan Zaman')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('published_at')
                    ->label('Yayınlanma')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('permalink')
                    ->label('Link')
                    ->url(fn (Publication $record): ?string => $record->permalink)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('error_category')
                    ->label('Hata Türü')
                    ->badge()
                    ->state(fn (Publication $record): string => Publication::errorCategories()[$record->errorCategory()])
                    ->color(fn (Publication $record): string => Publication::errorCategoryColor($record->errorCategory()))
                    ->visible(fn (?Publication $record): bool => filled($record?->error_message))
                    ->toggleable(),

                TextColumn::make('error_message')
                    ->label('Hata')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('publish_now')
                    ->label('Şimdi yayınla')
                    ->icon(Heroicon::PaperAirplane)
                    ->requiresConfirmation()
                    ->modalHeading('Instagram\'da yayınla')
                    ->modalDescription('Yayın Instagram hesabında hemen paylaşılacak. Bu işlem geri alınamaz.')
                    ->visible(fn (Publication $record): bool => $record->status !== Publication::STATUS_PUBLISHED
                        && auth()->user()?->can('publish', $record))
                    ->action(function (Publication $record): void {
                        try {
                            app(PublicationPublishingService::class)->publishNow($record);

                            Notification::make()
                                ->title('Yayınlandı')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Yayınlanamadı')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('cancel')
                    ->label('İptal et')
                    ->icon(Heroicon::Clock)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Zamanlamayı iptal et')
                    ->modalDescription('Yayın taslak durumuna dönecek; istediğiniz zaman yeniden zamanlayabilirsiniz.')
                    ->visible(fn (Publication $record): bool => $record->status === Publication::STATUS_SCHEDULED
                        && auth()->user()?->can('update', $record))
                    ->action(function (Publication $record): void {
                        // Mevcut unschedule davranışının karşılığı: taslağa dön.
                        $record->forceFill([
                            'status' => Publication::STATUS_DRAFT,
                            'scheduled_at' => null,
                            'error_message' => null,
                        ])->save();

                        Notification::make()
                            ->title('Zamanlama iptal edildi')
                            ->success()
                            ->send();
                    }),

                Action::make('retry')
                    ->label('Tekrar dene')
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Yeniden denensin mi?')
                    ->modalDescription('Yayın yeniden zamanlanacak ve kuyruk üzerinden tekrar yayınlanmaya çalışılacak.')
                    ->visible(fn (Publication $record): bool => in_array($record->status, [
                        Publication::STATUS_FAILED,
                        Publication::STATUS_FLAGGED,
                    ], true) && auth()->user()?->can('publish', $record))
                    ->action(function (Publication $record): void {
                        $record->forceFill([
                            'status' => Publication::STATUS_SCHEDULED,
                            'error_message' => null,
                            'scheduled_at' => now(),
                        ])->save();

                        PublishScheduledPublication::dispatch($record);

                        Notification::make()
                            ->title('Yayın kuyruğa alındı')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
