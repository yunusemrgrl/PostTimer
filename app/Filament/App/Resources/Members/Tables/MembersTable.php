<?php

namespace App\Filament\App\Resources\Members\Tables;

use App\Models\TeamMember;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Ad Soyad')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.email')
                    ->label('E-posta')
                    ->searchable(),

                TextColumn::make('role')
                    ->label('Rol')
                    ->formatStateUsing(fn (string $state) => TeamMember::roles()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        TeamMember::ROLE_OWNER => 'danger',
                        TeamMember::ROLE_ADMIN => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Katılım')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->label('Çıkar')
                    ->visible(fn (TeamMember $record) => $record->role !== TeamMember::ROLE_OWNER),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
