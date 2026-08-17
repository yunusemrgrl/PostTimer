<?php

namespace App\Filament\Admin\Resources\Roles\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Rol adı')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255)
                ->helperText('Örn: super_admin, support'),

            CheckboxList::make('permissions')
                ->label('İzinler')
                ->relationship('permissions', 'name')
                ->columns(2)
                ->searchable(),
        ]);
    }
}
