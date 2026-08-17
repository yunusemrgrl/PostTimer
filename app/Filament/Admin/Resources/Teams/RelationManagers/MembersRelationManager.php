<?php

namespace App\Filament\Admin\Resources\Teams\RelationManagers;

use App\Models\TeamMember;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Süper adminin, hesap (Team) düzenleme sayfasından doğrudan üyeleri
 * (kullanıcı + rol) yönetebilmesini sağlar. Bu, "yönetici bütün
 * hesaplara erişip yönetebilsin" gereksiniminin somut karşılığıdır.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Üyeler';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('role')
                ->label('Rol')
                ->options(TeamMember::roles())
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Ad'),
                TextColumn::make('email')->label('E-posta'),
                TextColumn::make('pivot.role')
                    ->label('Rol')
                    ->formatStateUsing(fn (?string $state) => TeamMember::roles()[$state] ?? $state)
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('role')
                            ->label('Rol')
                            ->options(TeamMember::roles())
                            ->default(TeamMember::ROLE_MEMBER)
                            ->required(),
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make(),
            ])
            ->toolbarActions([
                DetachBulkAction::make(),
            ]);
    }
}
