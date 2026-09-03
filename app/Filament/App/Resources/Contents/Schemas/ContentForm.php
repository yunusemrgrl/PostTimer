<?php

namespace App\Filament\App\Resources\Contents\Schemas;

use App\Models\Content;
use App\Models\Media;
use Awcodes\Curator\Components\Forms\CuratorPicker;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ContentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('İçerik')
                    ->schema([
                        Select::make('type')
                            ->label('Medya Türü')
                            ->options(Content::types())
                            ->default(Content::TYPE_IMAGE)
                            ->live()
                            ->required()
                            ->columnSpanFull(),

                        Select::make('surface')
                            ->label('Yayın Yüzeyi')
                            ->options(Content::surfaces())
                            ->default(Content::SURFACE_FEED)
                            ->live()
                            ->required()
                            ->columnSpanFull(),

                        CuratorPicker::make('media_url')
                            ->label('Medya')
                            ->buttonLabel('Medya seç')
                            ->acceptedFileTypes(['image/*', 'video/*'])
                            ->maxSize(102400)
                            ->required(fn (Get $get): bool => $get('type') !== Content::TYPE_CAROUSEL_ALBUM)
                            ->visible(fn (Get $get): bool => $get('type') !== Content::TYPE_CAROUSEL_ALBUM)
                            ->afterStateHydrated(function (CuratorPicker $component, mixed $state): void {
                                // Mevcut public URL'den Curator medyasını geri yükle
                                $url = is_string($state) && $state !== ''
                                    ? $state
                                    : $component->getRecord()?->media_url;

                                // Curator picker state'i HER ZAMAN array olmalı —
                                // aksi halde picker blade count(string) ile çöker.
                                // URL, Curator DB'de karşılığı olmayan
                                // (manuel/dış kaynak/legacy) bir kayıtsa state'i
                                // boş array'e çek; değer kaydedilirken
                                // dehydrateStateUsing üzerinden korunur.
                                if (! is_string($url) || $url === '') {
                                    $component->state([]);

                                    return;
                                }

                                $media = Media::findByPublicUrl($url);

                                $component->state($media !== null
                                    ? [(string) Str::uuid() => $media->toArray()]
                                    : []);
                            })
                            ->dehydrateStateUsing(function (CuratorPicker $component, mixed $state): ?string {
                                $item = collect($state ?? [])->first();

                                if (is_array($item) && isset($item['disk'], $item['path'])) {
                                    return Media::resolveUrl(
                                        (string) $item['disk'],
                                        (string) $item['path'],
                                        $item['visibility'] ?? null,
                                    );
                                }

                                // Medya seçilmediyse mevcut URL'yi koru (eski manuel kayıtlar)
                                return $component->getRecord()?->media_url;
                            })
                            ->helperText('Curator kütüphanesinden medya seçin; URL otomatik doldurulur. Medya, Instagram\'ın erişebilmesi için herkese açık bir diskte barındırılmalıdır.')
                            ->columnSpanFull(),

                        CuratorPicker::make('carousel_media')
                            ->label('Karusel Medyaları')
                            ->buttonLabel('Medya ekle')
                            ->multiple()
                            ->maxItems(10)
                            ->rule('min:2')
                            ->acceptedFileTypes(['image/*', 'video/*'])
                            ->visible(fn (Get $get): bool => $get('type') === Content::TYPE_CAROUSEL_ALBUM)
                            ->afterStateHydrated(function (CuratorPicker $component, mixed $state): void {
                                $items = [];

                                foreach (collect($component->getRecord()?->children ?? [])->filter() as $child) {
                                    $url = is_array($child) ? (string) ($child['url'] ?? '') : (string) $child;

                                    $media = $url === '' ? null : Media::findByPublicUrl($url);

                                    if ($media !== null) {
                                        $items[(string) Str::uuid()] = $media->toArray();
                                    }
                                }

                                if ($items !== []) {
                                    $component->state($items);
                                }
                            })
                            ->dehydrateStateUsing(function (CuratorPicker $component, mixed $state): array {
                                $children = collect($state ?? [])
                                    ->filter(fn (mixed $item): bool => is_array($item) && isset($item['disk'], $item['path']))
                                    ->map(fn (array $item): array => [
                                        'url' => Media::resolveUrl(
                                            (string) $item['disk'],
                                            (string) $item['path'],
                                            $item['visibility'] ?? null,
                                        ),
                                    ])
                                    ->values()
                                    ->all();

                                // Seçim yoksa mevcut children'ı koru
                                return $children !== [] ? $children : ($component->getRecord()?->children ?? []);
                            })
                            ->helperText('2 ile 10 arasında medya seçin.')
                            ->columnSpanFull(),

                        Textarea::make('caption')
                            ->label('Açıklama')
                            ->maxLength(2200)
                            // Canlı önizleme paneli caption'ı entangle ile okur;
                            // live(debounce) sayesinde yazarken akıcı güncellenir.
                            ->live(debounce: 600)
                            ->columnSpanFull(),

                        // Story → Link Sticker
                        TextInput::make('story_link')
                            ->label('Link Sticker URL')
                            ->url()
                            ->visible(fn (Get $get): bool => $get('surface') === Content::SURFACE_STORY)
                            ->helperText('Hikaye üzerinde tıklanabilir link sticker olarak görünür.')
                            ->columnSpanFull(),

                        // Reels/Post/Karusel → Otomatik İlk Yorum
                        Textarea::make('first_comment')
                            ->label('Otomatik İlk Yorum')
                            ->maxLength(2200)
                            ->visible(fn (Get $get): bool => $get('surface') !== Content::SURFACE_STORY)
                            ->helperText('Yayınlandıktan sonra otomatik olarak bu yorum atanır (affiliate link için ideal).')
                            ->columnSpanFull(),

                        TextInput::make('alt_text')
                            ->label('Alt Metin')
                            ->maxLength(1000)
                            ->visible(fn (Get $get): bool => $get('type') === Content::TYPE_IMAGE)
                            ->columnSpanFull(),

                        Toggle::make('is_ai_generated')
                            ->label('Yapay zekâ ile üretildi')
                            ->default(false)
                            ->columnSpanFull(),
                    ]),

                // Link Vault ürün seçimi
                Section::make('Link Vault')
                    ->description('Bu içeriği Link Vault\'taki bir ürüne bağlayın.')
                    ->schema([
                        Select::make('product_id')
                            ->label('Ürün')
                            ->options(function (): array {
                                return Filament::getTenant()
                                    ?->products()
                                    ->pluck('title', 'id')
                                    ->all() ?? [];
                            })
                            ->searchable()
                            ->preload()
                            ->helperText('Ürün seçerseniz, bu içerikten türeyen yayınlar stok kontrolüne dahil edilir.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
