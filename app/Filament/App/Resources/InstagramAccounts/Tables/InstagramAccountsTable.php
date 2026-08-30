<?php

namespace App\Filament\App\Resources\InstagramAccounts\Tables;

use App\Domain\Instagram\Services\InstagramAccountService;
use App\Models\InstagramAccount;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class InstagramAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('username')
                    ->label('Kullanıcı Adı')
                    ->formatStateUsing(fn (?string $state) => $state ? '@'.$state : '—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('ig_user_id')
                    ->label('Hesap ID')
                    ->copyable(),

                TextColumn::make('account_type')
                    ->label('Tür')
                    ->formatStateUsing(fn (?string $state) => $state ? (InstagramAccount::accountTypes()[$state] ?? $state) : '—')
                    ->badge()
                    ->color(fn (?string $state) => $state === InstagramAccount::TYPE_BUSINESS ? 'info' : 'warning'),

                TextColumn::make('followers_count')
                    ->label('Takipçi')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('media_count')
                    ->label('Medya')
                    ->numeric(),

                TextColumn::make('last_synced_at')
                    ->label('Son Senkron')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Hiç'),
            ])
            ->recordActions([
                Action::make('refresh')
                    ->label('Yenile')
                    ->icon(Heroicon::ArrowPath)
                    ->requiresConfirmation()
                    ->modalHeading('Hesap bilgilerini yenile')
                    ->modalDescription('Profil bilgileri Instagram Graph API\'den tekrar çekilecek.')
                    ->action(function (InstagramAccount $record): void {
                        try {
                            app(InstagramAccountService::class)->sync($record);

                            Notification::make()
                                ->title('Hesap bilgileri güncellendi')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Senkronizasyon başarısız')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
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
