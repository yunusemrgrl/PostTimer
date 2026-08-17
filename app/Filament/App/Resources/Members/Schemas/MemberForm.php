<?php

namespace App\Filament\App\Resources\Members\Schemas;

use App\Models\TeamMember;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Database\Eloquent\Builder;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('Kullanıcı')
                ->relationship(
                    name: 'user',
                    titleAttribute: 'name',
                    modifyQueryUsing: function (Builder $query) {
                        $team = Filament::getTenant();

                        return $query->whereDoesntHave('teams', fn (Builder $q) => $q->where('teams.id', $team?->getKey())
                        );
                    },
                )
                ->searchable(['name', 'email'])
                ->preload()
                ->required()
                ->disabledOn(Operation::Edit),

            Select::make('role')
                ->label('Rol')
                ->options(TeamMember::roles())
                ->default(TeamMember::ROLE_MEMBER)
                ->required(),
        ]);
    }
}
