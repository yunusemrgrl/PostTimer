<?php

namespace App\Filament\App\Resources\InstagramAccounts\Pages;

use App\Filament\App\Resources\InstagramAccounts\InstagramAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInstagramAccount extends EditRecord
{
    protected static string $resource = InstagramAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
