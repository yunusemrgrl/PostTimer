<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\InstagramPosts\InstagramPostResource;
use App\Models\InstagramPost;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestInstagramPostsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Son Gönderiler';

    public function table(Table $table): Table
    {
        $team = Filament::getTenant();

        return $table
            ->query(
                $team
                    ? InstagramPost::query()
                        ->whereBelongsTo($team, 'team')
                        ->latest('created_at')
                        ->limit(5)
                    : InstagramPost::query()->whereRaw('1 = 0')
            )
            ->columns([
                TextColumn::make('media_type')
                    ->label('Tür')
                    ->formatStateUsing(fn (string $state) => InstagramPost::mediaTypes()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => InstagramPost::mediaTypeColor($state)),

                TextColumn::make('caption')
                    ->label('Açıklama')
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Durum')
                    ->formatStateUsing(fn (string $state) => InstagramPost::statuses()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => InstagramPost::statusColor($state)),

                TextColumn::make('scheduled_at')
                    ->label('Zaman')
                    ->dateTime('d.m H:i')
                    ->placeholder('—'),

                TextColumn::make('published_at')
                    ->label('Yayın')
                    ->dateTime('d.m H:i')
                    ->placeholder('—'),
            ])
            ->recordUrl(
                fn (InstagramPost $record): string => InstagramPostResource::getUrl(
                    'edit',
                    ['record' => $record, 'tenant' => Filament::getTenant()],
                ),
            );
    }
}
