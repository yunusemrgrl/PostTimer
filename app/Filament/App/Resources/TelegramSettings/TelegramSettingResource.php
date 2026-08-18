<?php

namespace App\Filament\App\Resources\TelegramSettings;

use App\Filament\App\Resources\TelegramSettings\Pages\CreateTelegramSetting;
use App\Filament\App\Resources\TelegramSettings\Pages\EditTelegramSetting;
use App\Filament\App\Resources\TelegramSettings\Pages\ListTelegramSettings;
use App\Filament\App\Resources\TelegramSettings\Schemas\TelegramSettingForm;
use App\Filament\App\Resources\TelegramSettings\Tables\TelegramSettingsTable;
use App\Models\TelegramSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TelegramSettingResource extends Resource
{
    protected static ?string $model = TelegramSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Instagram';

    protected static ?string $navigationLabel = 'Telegram';

    protected static ?string $modelLabel = 'telegram ayarı';

    protected static ?string $pluralModelLabel = 'telegram ayarları';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return TelegramSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TelegramSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramSettings::route('/'),
            'create' => CreateTelegramSetting::route('/create'),
            'edit' => EditTelegramSetting::route('/{record}/edit'),
        ];
    }
}
