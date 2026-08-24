<?php

namespace App\Filament\App\Resources\Contents\Pages;

use App\Filament\App\Resources\Contents\ContentResource;
use App\Models\Content;
use Filament\Resources\Pages\CreateRecord;

class CreateContent extends CreateRecord
{
    protected static string $resource = ContentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Content::resolveCarouselMedia($data);
    }
}
