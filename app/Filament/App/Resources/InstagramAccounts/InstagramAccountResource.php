<?php

namespace App\Filament\App\Resources\InstagramAccounts;

use App\Filament\App\Resources\InstagramAccounts\Pages\CreateInstagramAccount;
use App\Filament\App\Resources\InstagramAccounts\Pages\EditInstagramAccount;
use App\Filament\App\Resources\InstagramAccounts\Pages\ListInstagramAccounts;
use App\Filament\App\Resources\InstagramAccounts\Schemas\InstagramAccountForm;
use App\Filament\App\Resources\InstagramAccounts\Tables\InstagramAccountsTable;
use App\Models\InstagramAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tenant panelindeki bu kaynak, InstagramAccount modelinin `team()` ilişkisi
 * sayesinde OTOMATİK olarak aktif hesaba (tenant) göre filtrelenir ve yeni
 * kayıtlar otomatik olarak o hesaba bağlanır (Filament'in "ownership
 * relationship" mekanizması).
 */
class InstagramAccountResource extends Resource
{
    protected static ?string $model = InstagramAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAtSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Instagram';

    protected static ?string $navigationLabel = 'Hesaplar';

    protected static ?string $modelLabel = 'hesap';

    protected static ?string $pluralModelLabel = 'hesaplar';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'username';

    public static function form(Schema $schema): Schema
    {
        return InstagramAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstagramAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstagramAccounts::route('/'),
            'create' => CreateInstagramAccount::route('/create'),
            'edit' => EditInstagramAccount::route('/{record}/edit'),
        ];
    }
}
