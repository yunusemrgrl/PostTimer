<?php

namespace App\Filament\App\Resources\TelegramSettings\Pages;

use App\Filament\App\Resources\TelegramSettings\TelegramSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramSettings extends ListRecords
{
    protected static string $resource = TelegramSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Telegram ekle'),
        ];
    }
}
