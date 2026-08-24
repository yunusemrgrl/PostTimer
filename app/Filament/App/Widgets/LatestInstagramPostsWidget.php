<?php

namespace App\Filament\App\Widgets;

use App\Filament\App\Resources\Contents\ContentResource;
use App\Models\Content;
use App\Models\Publication;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestInstagramPostsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected static ?string $heading = 'Son Yayınlar';

    public function table(Table $table): Table
    {
        $team = Filament::getTenant();

        return $table
            ->query(
                $team
                    ? Publication::query()
                        ->with(['content', 'instagramAccount'])
                        ->whereBelongsTo($team, 'team')
                        ->latest('published_at')
                        ->limit(5)
                    : Publication::query()->whereRaw('1 = 0')
            )
            ->columns([
                TextColumn::make('instagramAccount.username')
                    ->label('Hesap')
                    ->formatStateUsing(fn (Publication $record) => $record->instagramAccount
                        ? '@'.$record->instagramAccount->username
                        : '—')
                    ->placeholder('—'),

                TextColumn::make('content.type')
                    ->label('Tür')
                    ->formatStateUsing(fn (Publication $record) => Content::types()[$record->content?->type] ?? $record->content?->type)
                    ->badge()
                    ->color(fn (Publication $record) => match ($record->content?->type) {
                        Content::TYPE_CAROUSEL_ALBUM => 'info',
                        Content::TYPE_VIDEO => 'purple',
                        default => 'gray',
                    }),

                TextColumn::make('content.caption')
                    ->label('Açıklama')
                    ->limit(40)
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Durum')
                    ->formatStateUsing(fn (string $state) => Publication::statuses()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => Publication::statusColor($state)),

                TextColumn::make('published_at')
                    ->label('Yayın')
                    ->dateTime('d.m H:i')
                    ->placeholder('—'),
            ])
            ->recordUrl(
                fn (Publication $record): string => $record->content
                    ? ContentResource::getUrl(
                        'edit',
                        ['record' => $record->content, 'tenant' => Filament::getTenant()],
                    )
                    : '#',
            );
    }
}
