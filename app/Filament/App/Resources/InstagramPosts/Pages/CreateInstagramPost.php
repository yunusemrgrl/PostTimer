<?php

namespace App\Filament\App\Resources\InstagramPosts\Pages;

use App\Filament\App\Resources\InstagramPosts\InstagramPostResource;
use App\Models\InstagramPost;
use Filament\Resources\Pages\CreateRecord;

class CreateInstagramPost extends CreateRecord
{
    protected static string $resource = InstagramPostResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = InstagramPost::resolveCarouselMedia($data);

        return InstagramPost::resolveScheduling($data);
    }
}
