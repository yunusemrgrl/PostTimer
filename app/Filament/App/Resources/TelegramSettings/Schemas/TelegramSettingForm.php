<?php

namespace App\Filament\App\Resources\TelegramSettings\Schemas;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
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
                Section::make('Telegram Bot')
                    ->description("@BotFather'dan bot oluşturun ve token'ı yapıştırın.")
                    ->schema([
                        TextInput::make('bot_token')
                            ->label('Bot Token')
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText('@BotFather → /newbot → alınan token')
                            ->columnSpanFull(),

                        Placeholder::make('verification_status')
                            ->label('Doğrulama Durumu')
                            ->content(function (Get $get): string {
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
                            ->helperText('Bu kodu Telegram botunuza /start <kod> şeklinde gönderin.')
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
