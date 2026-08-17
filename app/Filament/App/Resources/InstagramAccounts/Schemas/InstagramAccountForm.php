<?php

namespace App\Filament\App\Resources\InstagramAccounts\Schemas;

use App\Models\InstagramAccount;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InstagramAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Hesap Bağlantısı')
                    ->description('Instagram profesyonel hesabının Graph API kimliği ve erişim jetonu.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('ig_user_id')
                                ->label('Instagram Hesap ID')
                                ->required()
                                ->maxLength(64)
                                ->columnSpan(1),

                            Select::make('api_host')
                                ->label('API Host')
                                ->options([
                                    'graph.instagram.com' => 'Instagram Login (graph.instagram.com)',
                                    'graph.facebook.com' => 'Facebook Login (graph.facebook.com)',
                                ])
                                ->default('graph.instagram.com')
                                ->required()
                                ->helperText('Business Login (Instagram ile Bağlan) ile bağlanan hesaplarda graph.instagram.com kullanılır.')
                                ->columnSpan(1),

                            TextInput::make('access_token')
                                ->label('Erişim Jetonu')
                                ->password()
                                ->revealable()
                                ->required()
                                ->helperText('"Instagram ile Bağlan" akışı bu alanı otomatik doldurur.')
                                ->columnSpanFull(),
                        ]),
                    ]),

                Section::make('Profil (API\'den senkronize edilir)')
                    ->description('Bu alanlar "Yenile" aksiyonuyla Instagram\'dan güncellenir; elle değiştirilmez.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('username')
                                ->label('Kullanıcı Adı')
                                ->prefix('@')
                                ->disabled(),

                            TextInput::make('account_type')
                                ->label('Hesap Türü')
                                ->formatStateUsing(fn (?string $state) => $state ? (InstagramAccount::accountTypes()[$state] ?? $state) : null)
                                ->disabled(),

                            TextInput::make('followers_count')
                                ->label('Takipçi')
                                ->numeric()
                                ->disabled(),
                        ]),
                        Grid::make(3)->schema([
                            TextInput::make('name')
                                ->label('Ad')
                                ->disabled(),

                            TextInput::make('media_count')
                                ->label('Medya Sayısı')
                                ->numeric()
                                ->disabled(),

                            TextInput::make('website')
                                ->label('Web Sitesi')
                                ->disabled(),
                        ]),
                    ]),
            ]);
    }
}
