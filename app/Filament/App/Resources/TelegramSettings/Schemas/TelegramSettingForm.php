<?php

namespace App\Filament\App\Resources\TelegramSettings\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TelegramSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Telegram Bildirimleri')
                    ->description('Bildirimler tek ortak bot olan @posttimer_cloud_bot üzerinden bu hesaba gelir.')
                    ->schema([
                        TextEntry::make('verification_status')
                            ->label('Doğrulama Durumu')
                            ->state(function (Get $get): string {
                                $chatId = $get('chat_id');
                                $verified = $get('is_verified');

                                if ($verified && $chatId) {
                                    return "✅ Doğrulandı (Chat ID: {$chatId})";
                                }

                                return '❌ Henüz doğrulanmadı';
                            }),

                        TextInput::make('verification_code')
                            ->label('Doğrulama Kodu')
                            ->readonly()
                            ->helperText('1. Telegram\'da @posttimer_cloud_bot\'u aç; 2. /start <kod> şeklinde bu kodu gönder; 3. Bot "Doğrulama başarılı" derse bildirimler bu sohbete gelir.')
                            ->suffixAction(
                                Action::make('generate_code')
                                    ->label('Yeni kod')
                                    ->icon('heroicon-m-arrow-path')
                                    ->action(function (Get $get, Set $set): void {
                                        $set('verification_code', Str::random(12));
                                    })
                            ),
                    ]),
            ]);
    }
}
