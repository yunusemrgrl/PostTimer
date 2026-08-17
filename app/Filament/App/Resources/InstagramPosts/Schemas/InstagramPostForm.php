<?php

namespace App\Filament\App\Resources\InstagramPosts\Schemas;

use App\Models\InstagramPost;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class InstagramPostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Gönderi')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('ig_user_id')
                                ->label('Instagram Hesabı')
                                ->options(function (): array {
                                    return Filament::getTenant()
                                        ?->instagramAccounts()
                                        ->pluck('username', 'ig_user_id')
                                        ->map(fn (?string $username) => $username ? '@'.$username : 'Bilinmeyen hesap')
                                        ->all() ?? [];
                                })
                                ->searchable()
                                ->required()
                                ->columnSpan(1),

                            Select::make('media_type')
                                ->label('Medya Türü')
                                ->options(InstagramPost::mediaTypes())
                                ->default(InstagramPost::MEDIA_TYPE_IMAGE)
                                ->live()
                                ->required()
                                ->columnSpan(1),

                            TextInput::make('media_url')
                                ->label('Medya URL')
                                ->url()
                                ->required(fn (Get $get): bool => $get('media_type') !== InstagramPost::MEDIA_TYPE_CAROUSEL)
                                ->visible(fn (Get $get): bool => $get('media_type') !== InstagramPost::MEDIA_TYPE_CAROUSEL)
                                ->helperText('Medya, Instagram\'ın erişebilmesi için herkese açık bir sunucuda barındırılmalıdır.')
                                ->columnSpanFull(),

                            Repeater::make('children')
                                ->label('Karusel Medyaları')
                                ->schema([
                                    TextInput::make('url')
                                        ->label('Medya URL')
                                        ->url()
                                        ->required(),
                                ])
                                ->addActionLabel('Medya ekle')
                                ->minItems(2)
                                ->maxItems(10)
                                ->visible(fn (Get $get): bool => $get('media_type') === InstagramPost::MEDIA_TYPE_CAROUSEL)
                                ->columnSpanFull(),

                            Textarea::make('caption')
                                ->label('Açıklama')
                                ->maxLength(2200)
                                ->columnSpanFull(),

                            TextInput::make('alt_text')
                                ->label('Alt Metin')
                                ->maxLength(1000)
                                ->visible(fn (Get $get): bool => $get('media_type') === InstagramPost::MEDIA_TYPE_IMAGE)
                                ->columnSpanFull(),

                            Toggle::make('is_ai_generated')
                                ->label('Yapay zekâ ile üretildi')
                                ->default(false)
                                ->columnSpanFull(),

                            DateTimePicker::make('scheduled_at')
                                ->label('Yayınlanma zamanı')
                                ->helperText('Boş bırakırsanız gönderi "Yayınla" ile anında yayınlanır. Gelecek bir tarih seçerseniz gönderi o tarihte otomatik yayınlanır.')
                                ->minDate(now())
                                ->seconds(false)
                                ->columnSpanFull(),
                        ]),
                    ]),
            ]);
    }
}
