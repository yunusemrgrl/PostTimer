<?php

namespace App\Filament\App\Resources\Products\Schemas;

use App\Domain\Stock\Services\AmazonProductParser;
use App\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ürün Linki')
                    ->description('Amazon affiliate linkini yapıştırın — başlık ve görsel otomatik çekilir.')
                    ->schema([
                        Select::make('platform')
                            ->label('Platform')
                            ->options(Product::platforms())
                            ->default(Product::PLATFORM_AMAZON)
                            ->required()
                            ->disabled(),

                        TextInput::make('url')
                            ->label('Amazon Linki')
                            ->url()
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                if (! $state) {
                                    return;
                                }

                                $parsed = app(AmazonProductParser::class)->parse($state);

                                $set('asin', $parsed['asin']);
                                $set('title', $parsed['title']);
                                $set('image_url', $parsed['image_url']);
                            })
                            ->helperText('Linki yapıştırıp çıkın (blur) — ASIN, başlık ve görsel otomatik gelir. Çekilemezse elle düzenleyebilirsiniz.'),
                    ]),

                Section::make('Ürün Bilgileri')
                    ->description('Otomatik doldurulur; gerekirse elle düzenleyebilirsiniz.')
                    ->schema([
                        TextInput::make('asin')
                            ->label('ASIN')
                            ->maxLength(10)
                            ->dehydrated()
                            ->disabled(),

                        TextInput::make('title')
                            ->label('Ürün Adı')
                            ->maxLength(500)
                            ->columnSpanFull(),

                        TextInput::make('image_url')
                            ->label('Kapak Görseli URL')
                            ->url()
                            ->maxLength(1000)
                            ->columnSpanFull(),

                        TextInput::make('category')
                            ->label('Kategori')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
