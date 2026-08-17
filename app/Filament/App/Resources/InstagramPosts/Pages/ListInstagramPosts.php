<?php

namespace App\Filament\App\Resources\InstagramPosts\Pages;

use App\Filament\App\Resources\InstagramPosts\InstagramPostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInstagramPosts extends ListRecords
{
    protected static string $resource = InstagramPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Gönderi ekle'),
        ];
    }
}
