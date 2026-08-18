<?php

namespace App\Filament\App\Resources\TelegramSettings\Pages;

use App\Filament\App\Resources\TelegramSettings\TelegramSettingResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateTelegramSetting extends CreateRecord
{
    protected static string $resource = TelegramSettingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['webhook_secret'] = Str::random(32);

        return $data;
    }
}
