<?php

namespace App\Filament\App\Resources\InstagramPosts\Tables;

use App\Models\InstagramPost;
use App\Services\PublishInstagramPostService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class InstagramPostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('media_type')
                    ->label('Tür')
                    ->formatStateUsing(fn (string $state) => InstagramPost::mediaTypes()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => InstagramPost::mediaTypeColor($state)),

                TextColumn::make('caption')
                    ->label('Açıklama')
                    ->limit(50)
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Durum')
                    ->formatStateUsing(fn (string $state) => InstagramPost::statuses()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => InstagramPost::statusColor($state)),

                TextColumn::make('scheduled_at')
                    ->label('Planlanan Zaman')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('media_id')
                    ->label('Instagram Medya ID')
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('published_at')
                    ->label('Yayınlanma')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('media_type')
                    ->label('Tür')
                    ->options(InstagramPost::mediaTypes()),

                SelectFilter::make('status')
                    ->label('Durum')
                    ->options(InstagramPost::statuses()),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label(fn (InstagramPost $record): string => $record->isScheduled() ? 'Şimdi yayınla' : 'Yayınla')
                    ->icon(Heroicon::PaperAirplane)
                    ->requiresConfirmation()
                    ->modalHeading(fn (InstagramPost $record): string => $record->isScheduled() ? 'Planı iptal et ve şimdi yayınla' : 'Instagram\'da yayınla')
                    ->modalDescription(fn (InstagramPost $record): string => $record->isScheduled()
                        ? 'Gönderinin zamanlaması iptal edilip hemen yayınlanacak. Bu işlem geri alınamaz.'
                        : 'Gönderi Instagram hesabında yayınlanacak. Bu işlem geri alınamaz.')
                    ->visible(fn (InstagramPost $record): bool => $record->status !== InstagramPost::STATUS_PUBLISHED
                        && auth()->user()?->can('publish', $record))
                    ->action(function (InstagramPost $record): void {
                        try {
                            app(PublishInstagramPostService::class)->publishNow($record);

                            Notification::make()
                                ->title('Gönderi yayınlandı')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Gönderi yayınlanamadı')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('unschedule')
                    ->label('İptal et')
                    ->icon(Heroicon::Clock)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Zamanlamayı iptal et')
                    ->modalDescription('Gönderi taslak durumuna dönecek; istediğiniz zaman yeniden zamanlayabilir veya yayınlayabilirsiniz.')
                    ->visible(fn (InstagramPost $record): bool => $record->isScheduled()
                        && auth()->user()?->can('update', $record))
                    ->action(function (InstagramPost $record): void {
                        app(PublishInstagramPostService::class)->unschedule($record);

                        Notification::make()
                            ->title('Zamanlama iptal edildi')
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make()->label('Sil'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
