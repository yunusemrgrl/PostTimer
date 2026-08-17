<?php

namespace App\Filament\App\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('Görsel')
                    ->imageSize(40)
                    ->defaultImageUrl(url('https://placehold.co/40x40?text=Yok')),

                TextColumn::make('title')
                    ->label('Ürün Adı')
                    ->limit(50)
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('asin')
                    ->label('ASIN')
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('platform')
                    ->label('Platform')
                    ->formatStateUsing(fn (string $state) => Product::platforms()[$state] ?? $state)
                    ->badge()
                    ->color('warning'),

                TextColumn::make('category')
                    ->label('Kategori')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Eklenme')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->label('Platform')
                    ->options(Product::platforms()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Sil'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
