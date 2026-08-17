<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Hesap bilgileri')
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->label('Ad Soyad')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('E-posta')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    TextInput::make('password')
                        ->label('Şifre')
                        ->password()
                        ->revealable()
                        ->required(fn (Operation $operation) => $operation === Operation::Create)
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText('Düzenlerken boş bırakılırsa şifre değişmez.')
                        ->maxLength(255),
                ]),

            Section::make('Platform yetkisi')
                ->description('Bu kullanıcıya süper admin rolü vermek, TÜM hesaplara sınırsız erişim sağlar.')
                ->components([
                    Select::make('roles')
                        ->label('Roller')
                        ->relationship('roles', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable(),
                ]),
        ]);
    }
}
