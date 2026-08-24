<?php

namespace App\Filament\App\Resources\Contents\Pages;

use App\Filament\App\Resources\Contents\ContentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContents extends ListRecords
{
    protected static string $resource = ContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('İçerik ekle'),
        ];
    }
}
