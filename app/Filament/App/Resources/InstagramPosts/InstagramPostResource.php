<?php

namespace App\Filament\App\Resources\InstagramPosts;

use App\Filament\App\Resources\InstagramPosts\Pages\CreateInstagramPost;
use App\Filament\App\Resources\InstagramPosts\Pages\EditInstagramPost;
use App\Filament\App\Resources\InstagramPosts\Pages\ListInstagramPosts;
use App\Filament\App\Resources\InstagramPosts\Schemas\InstagramPostForm;
use App\Filament\App\Resources\InstagramPosts\Tables\InstagramPostsTable;
use App\Models\InstagramPost;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tenant panelindeki bu kaynak, InstagramPost modelinin `team()` ilişkisi
 * sayesinde OTOMATİK olarak aktif hesaba (tenant) göre filtrelenir ve
 * yeni kayıtlar otomatik olarak o hesaba bağlanır (Filament'in
 * "ownership relationship" mekanizması).
 */
/**
 * LEGACY — InstagramPost domain pasif durumdadır. Yeni akış Content →
 * Publication'a taşıldı; bu resource navigasyondan gizlenilir (dosyalar
 * ve modeli eski 8 production kaydın erişilebilirliği için kalmıyaz).
 */
class InstagramPostResource extends Resource
{
    protected static ?string $model = InstagramPost::class;

    /**
     * Legacy Resource navigation'da artık gösterilmez.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Instagram';

    protected static ?string $navigationLabel = 'Gönderiler';

    protected static ?string $modelLabel = 'gönderi';

    protected static ?string $pluralModelLabel = 'gönderiler';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'caption';

    public static function form(Schema $schema): Schema
    {
        return InstagramPostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InstagramPostsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInstagramPosts::route('/'),
            'create' => CreateInstagramPost::route('/create'),
            'edit' => EditInstagramPost::route('/{record}/edit'),
        ];
    }
}
