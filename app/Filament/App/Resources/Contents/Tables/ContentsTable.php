<?php

namespace App\Filament\App\Resources\Contents\Tables;

use App\Models\Content;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('surface')
                    ->label('Tür')
                    ->formatStateUsing(fn (Content $record): string => Content::surfaces()[$record->surface]
                        ?? Content::types()[$record->type]
                        ?? $record->type)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'REELS' => 'success',
                        'STORY' => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('caption')
                    ->label('Açıklama')
                    ->limit(50)
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('product.title')
                    ->label('Ürün')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(),

                // Basit publication durum özeti (örn. "Yayınlandı: 2 · Zamanlandı: 1")
                TextColumn::make('publications_summary')
                    ->label('Yayınlar')
                    ->state(fn (Content $record): string => $record->publicationsSummary())
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Medya Türü')
                    ->options(Content::types()),

                SelectFilter::make('surface')
                    ->label('Yayın Yüzeyi')
                    ->options(Content::surfaces()),
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
