<?php

namespace App\Filament\App\Resources\InstagramAccounts\Pages;

use App\Filament\App\Resources\InstagramAccounts\InstagramAccountResource;
use App\Models\InstagramAccount;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInstagramAccounts extends ListRecords
{
    protected static string $resource = InstagramAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Instagram ile Bağlan')
                ->icon(Heroicon::Link)
                ->url(fn (): string => route('instagram.connect', [
                    'tenant' => filament()->getTenant()->slug,
                ]))
                ->visible(fn (): bool => filament()
                    ->auth()
                    ->user()
                    ->can('create', InstagramAccount::class)),

            CreateAction::make()->label('Manuel ekle'),
        ];
    }
}
