<?php

namespace App\Filament\App\Resources\Members\Pages;

use App\Filament\App\Resources\Members\MemberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Üye ekle'),
        ];
    }
}
