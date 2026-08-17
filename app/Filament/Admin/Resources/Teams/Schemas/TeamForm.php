<?php

namespace App\Filament\Admin\Resources\Teams\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TeamForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Hesap adı')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, ?string $state, callable $set) {
                    if ($operation === 'create') {
                        $set('slug', Str::slug($state));
                    }
                }),

            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('Tenant panelindeki URL için kullanılır: /app/{slug}'),

            Select::make('owner_id')
                ->label('Sahip (Owner)')
                ->relationship('owner', 'name')
                ->searchable()
                ->preload()
                ->helperText('Bu hesabın birincil sahibi. Üyeler, ayrı sekmedeki "Üyeler" bölümünden eklenir.'),
        ]);
    }
}
