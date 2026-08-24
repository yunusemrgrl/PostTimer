<?php

namespace App\Filament\App\Resources\Contents;

use App\Filament\App\Resources\Contents\Pages\CreateContent;
use App\Filament\App\Resources\Contents\Pages\EditContent;
use App\Filament\App\Resources\Contents\Pages\ListContents;
use App\Filament\App\Resources\Contents\RelationManagers\PublicationsRelationManager;
use App\Filament\App\Resources\Contents\Schemas\ContentForm;
use App\Filament\App\Resources\Contents\Tables\ContentsTable;
use App\Models\Content;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tenant panelindeki bu kaynak, Content modelinin `team()` ilişkisi
 * sayesinde OTOMATİK olarak aktif hesaba (tenant) göre filtrelenir ve
 * yeni kayıtlar otomatik olarak o hesaba bağlanır (Filament'in
 * "ownership relationship" mekanizması).
 *
 * İçerik hesap-bağımsızdır: hesap seçimi ve zamanlama, Content'in
 * hesaplara dağıtılmasında (Publication) yapılır.
 */
class ContentResource extends Resource
{
    protected static ?string $model = Content::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static string|UnitEnum|null $navigationGroup = 'Instagram';

    protected static ?string $navigationLabel = 'İçerikler';

    protected static ?string $modelLabel = 'içerik';

    protected static ?string $pluralModelLabel = 'içerikler';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'caption';

    public static function form(Schema $schema): Schema
    {
        return ContentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PublicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContents::route('/'),
            'create' => CreateContent::route('/create'),
            'edit' => EditContent::route('/{record}/edit'),
        ];
    }
}
