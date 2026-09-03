<?php

namespace App\Filament\App\Resources\Contents\Pages;

use App\Filament\App\Resources\Contents\ContentResource;
use App\Models\Content;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class CreateContent extends CreateRecord
{
    protected static string $resource = ContentResource::class;

    /**
     * Editör düzeni: solda Filament form motoru (EmbeddedSchema — binding,
     * validation, dehydrate ve Content::resolveCarouselMedia aynen çalışır),
     * sağda yalnızca OKUYAN canlı Instagram önizlemesi.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, '2xl' => 5])
                    ->schema([
                        $this->getFormContentComponent()
                            ->columnSpan(['default' => 1, '2xl' => 3]),

                        View::make('filament.app.components.content-preview')
                            ->columnSpan(['default' => 1, '2xl' => 2]),
                    ]),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return Content::resolveCarouselMedia($data);
    }
}
