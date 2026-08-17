<?php

namespace App\Filament\App\Resources\InstagramPosts\Pages;

use App\Filament\App\Resources\InstagramPosts\InstagramPostResource;
use App\Models\InstagramPost;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInstagramPost extends EditRecord
{
    protected static string $resource = InstagramPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return InstagramPost::resolveScheduling($data);
    }
}
