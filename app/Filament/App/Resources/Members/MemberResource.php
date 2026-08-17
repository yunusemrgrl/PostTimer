<?php

namespace App\Filament\App\Resources\Members;

use App\Filament\App\Resources\Members\Pages\CreateMember;
use App\Filament\App\Resources\Members\Pages\EditMember;
use App\Filament\App\Resources\Members\Pages\ListMembers;
use App\Filament\App\Resources\Members\Schemas\MemberForm;
use App\Filament\App\Resources\Members\Tables\MembersTable;
use App\Models\TeamMember;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tenant panelindeki bu kaynak, TeamMember modelinin `team()` ilişkisi
 * sayesinde OTOMATİK olarak aktif hesaba (tenant) göre filtrelenir ve
 * yeni kayıtlar otomatik olarak o hesaba bağlanır (Filament'in
 * "ownership relationship" mekanizması).
 */
class MemberResource extends Resource
{
    protected static ?string $model = TeamMember::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Ekip';

    protected static ?string $navigationLabel = 'Üyeler';

    protected static ?string $modelLabel = 'üye';

    protected static ?string $pluralModelLabel = 'üyeler';

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'edit' => EditMember::route('/{record}/edit'),
        ];
    }
}
