<?php

namespace App\Models;

use App\Domain\Instagram\HasPublishableMedia;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hesap-bağımsız içerik varlığı: Bir ürüne bağlı medya + caption.
 * Yayın kaydı Publication'dadır; aynı Content birden fazla hesaba
 * dağıtılabilir. Format (type) ve yayın yüzü (surface) iki ayrı
 * eksendir — Meta'nın IG Media modeliyle aynı değerleri kullanır.
 */
class Content extends Model implements HasPublishableMedia
{
    /** @use HasFactory<ContentFactory> */
    use HasFactory;

    public const TYPE_IMAGE = 'IMAGE';

    public const TYPE_VIDEO = 'VIDEO';

    public const TYPE_CAROUSEL_ALBUM = 'CAROUSEL_ALBUM';

    public const SURFACE_FEED = 'FEED';

    public const SURFACE_REELS = 'REELS';

    public const SURFACE_STORY = 'STORY';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'product_id',
        'type',
        'surface',
        'caption',
        'media_url',
        'thumbnail_url',
        'children',
        'alt_text',
        'story_link',
        'first_comment',
        'is_ai_generated',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_IMAGE => 'Görsel',
            self::TYPE_VIDEO => 'Video',
            self::TYPE_CAROUSEL_ALBUM => 'Karusel',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function surfaces(): array
    {
        return [
            self::SURFACE_FEED => 'Feed',
            self::SURFACE_REELS => 'Reels',
            self::SURFACE_STORY => 'Hikaye',
        ];
    }

    protected function casts(): array
    {
        return [
            'children' => 'array',
            'metadata' => 'array',
            'is_ai_generated' => 'boolean',
        ];
    }

    /**
     * Bu content'in yayın kayıtları (hesap başına en fazla bir tane).
     *
     * @return HasMany<Publication, $this>
     */
    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Filament'in App panelindeki ->tenant() kapsamı (automatic scoping)
     * bu ilişki üzerinden çalışır.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Formdaki geçici `carousel_media` CuratorPicker alanını mevcut `children`
     * JSON kolonuna taşır (InstagramPost::resolveCarouselMedia ile aynı desen).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolveCarouselMedia(array $data): array
    {
        if (array_key_exists('carousel_media', $data)) {
            $data['children'] = $data['carousel_media'] ?? [];

            unset($data['carousel_media']);
        }

        return $data;
    }

    /**
     * Content listesinde gösterilen basit publication durum özeti.
     * Örn: "Yayınlandı: 2 · Zamanlandı: 1". Yayın yoksa "—".
     */
    public function publicationsSummary(): string
    {
        $counts = $this->publications()
            ->get(['status'])
            ->groupBy('status')
            ->map(fn ($group) => $group->count());

        if ($counts->isEmpty()) {
            return '—';
        }

        return $counts
            ->map(fn (int $count, string $status) => Publication::statuses()[$status].': '.$count)
            ->implode(' · ');
    }

    public function isCarousel(): bool
    {
        return $this->type === self::TYPE_CAROUSEL_ALBUM;
    }

    public function isStory(): bool
    {
        return $this->surface === self::SURFACE_STORY;
    }

    // --- HasPublishableMedia sözleşmesi (InstagramMediaFactory kaynakları) ---

    public function getMediaType(): string
    {
        return $this->type;
    }

    public function getMediaProductType(): ?string
    {
        return $this->surface;
    }

    public function isVideo(): bool
    {
        return $this->type === self::TYPE_VIDEO;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function getAltText(): ?string
    {
        return $this->alt_text;
    }

    public function getMediaUrl(): ?string
    {
        return $this->media_url;
    }

    public function getStoryLink(): ?string
    {
        return $this->story_link;
    }

    /**
     * @return array<int, mixed>|null
     */
    public function getChildren(): ?array
    {
        return $this->children;
    }

    public function isAiGenerated(): bool
    {
        return $this->is_ai_generated;
    }
}
