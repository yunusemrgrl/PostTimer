<?php

namespace App\Models;

use App\Domain\Instagram\HasPublishableMedia;
use App\Domain\Instagram\InstagramMediaFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LEGACY — InstagramPost model'i. Bu domain pasif durumdadır; yeni akış
 * Content + Publication'a taşındı. Eski 8 production `instagram_posts` kaydı
 * hâlâ okunabilir durumdadur ancak yeni veri oluşturulmaz. Eski publish /
 * scheduler / event akışları deprecated sayılır.
 *
 * @deprecated Content + Publication model'lerini kullanın.
 */
class InstagramPost extends Model implements HasPublishableMedia
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FLAGGED = 'flagged';

    public const MEDIA_TYPE_IMAGE = 'IMAGE';

    public const MEDIA_TYPE_VIDEO = 'VIDEO';

    public const MEDIA_TYPE_REELS = 'REELS';

    public const MEDIA_TYPE_STORIES = 'STORIES';

    public const MEDIA_TYPE_CAROUSEL = 'CAROUSEL';

    public const MEDIA_TYPE_CAROUSEL_ALBUM = 'CAROUSEL_ALBUM';

    public const PRODUCT_TYPE_FEED = 'FEED';

    public const PRODUCT_TYPE_REELS = 'REELS';

    public const PRODUCT_TYPE_STORY = 'STORY';

    protected $fillable = [
        'team_id',
        'ig_user_id',
        'media_type',
        'media_product_type',
        'caption',
        'media_url',
        'thumbnail_url',
        'story_link',
        'first_comment',
        'children',
        'alt_text',
        'is_ai_generated',
        'product_id',
        'container_id',
        'media_id',
        'permalink',
        'like_count',
        'comments_count',
        'status',
        'scheduled_at',
        'error_message',
        'published_at',
        'ig_media_timestamp',
    ];

    /**
     * Mevcut UI için medya türü etiketleri. Eski REELS/STORIES/CAROUSEL
     * değerleri geriye dönük uyumluluk için korunur; yeni kod
     * media_type + media_product_type iki eksenini kullanmalıdır.
     *
     * @return array<string, string>
     */
    public static function mediaTypes(): array
    {
        return [
            self::MEDIA_TYPE_IMAGE => 'Görsel',
            self::MEDIA_TYPE_VIDEO => 'Video',
            self::MEDIA_TYPE_REELS => 'Reels',
            self::MEDIA_TYPE_STORIES => 'Hikaye',
            self::MEDIA_TYPE_CAROUSEL => 'Karusel',
        ];
    }

    /**
     * Meta'nın IG Media modeline uygun media_product_type etiketleri.
     *
     * @return array<string, string>
     */
    public static function productTypes(): array
    {
        return [
            self::PRODUCT_TYPE_FEED => 'Feed',
            self::PRODUCT_TYPE_REELS => 'Reels',
            self::PRODUCT_TYPE_STORY => 'Hikaye',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Taslak',
            self::STATUS_SCHEDULED => 'Zamanlandı',
            self::STATUS_PUBLISHING => 'Yayınlanıyor',
            self::STATUS_PUBLISHED => 'Yayınlandı',
            self::STATUS_FAILED => 'Başarısız',
            self::STATUS_FLAGGED => 'Uyarıldı',
        ];
    }

    /**
     * Belirli bir medya türü için Filament badge rengini döndürür.
     */
    public static function mediaTypeColor(string $type): string
    {
        return match ($type) {
            self::MEDIA_TYPE_REELS, self::PRODUCT_TYPE_REELS => 'info',
            self::MEDIA_TYPE_VIDEO => 'warning',
            self::MEDIA_TYPE_CAROUSEL, self::MEDIA_TYPE_CAROUSEL_ALBUM => 'success',
            self::MEDIA_TYPE_STORIES, self::PRODUCT_TYPE_STORY => 'gray',
            default => 'gray',
        };
    }

    /**
     * Belirli bir durum için Filament badge rengini döndürür.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PUBLISHED => 'success',
            self::STATUS_SCHEDULED => 'info',
            self::STATUS_PUBLISHING => 'warning',
            self::STATUS_FAILED => 'danger',
            self::STATUS_FLAGGED => 'warning',
            default => 'gray',
        };
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isFlagged(): bool
    {
        return $this->status === self::STATUS_FLAGGED;
    }

    public function isPublishing(): bool
    {
        return $this->status === self::STATUS_PUBLISHING;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Idempotency Pattern 1 — Atomic Claim:
     * Postu verilen kaynak durumdan "publishing" durumuna atomik geçirir.
     * Başka bir worker aynı postu almaya çalışırsa 0 döner → çift yayın engellenir.
     *
     * NOT: `fromStatuses` varsayılanına `publishing` de dahildir; böylece H1
     * retry akışında geçici bir hatadan sonra status'u `publishing`'de kalan
     * post, queue retry'ı tarafından yeniden claim edilip tekrar denenebilir.
     * Eşzamanlı çift yayını asıl engelleyen `Cache::lock`'tur; bu metot yalnızca
     * "hâlâ yayınlanmamış" tazeliğini doğrular.
     *
     * Aynı değere geçiş (publishing→publishing) `affected rows`'un 0 dönmesine
     * sebep olduğundan, güvenilir claim için `updated_at` da güncellenir.
     *
     * @param  array<int, string>  $fromStatuses
     */
    public function atomicClaim(array $fromStatuses = [
        self::STATUS_SCHEDULED,
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHING,
    ]): bool
    {
        return (bool) static::query()
            ->where('id', $this->id)
            ->whereIn('status', $fromStatuses)
            ->update([
                'status' => self::STATUS_PUBLISHING,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * Formdan gelen verilere göre zamanlama durumunu çözer:
     * gelecekte bir scheduled_at varsa ve gönderi henüz yayınlanmamışsa
     * durum "scheduled", aksi halde "draft" olur.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resolveScheduling(array $data): array
    {
        if (($data['status'] ?? null) === self::STATUS_PUBLISHED) {
            return $data;
        }

        $scheduledAt = $data['scheduled_at'] ?? null;

        if ($scheduledAt && Carbon::parse($scheduledAt)->isFuture()) {
            $data['status'] = self::STATUS_SCHEDULED;
        } else {
            $data['status'] = self::STATUS_DRAFT;
        }

        return $data;
    }

    /**
     * Formdaki geçici `carousel_media` CuratorPicker alanını mevcut `children`
     * JSON kolonuna taşır. Her çocuğun public URL'si form katmanında çözülür;
     * burada yalnızca alan eşlemesi yapılır.
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

    public function isVideo(): bool
    {
        if (in_array($this->media_type, [
            self::MEDIA_TYPE_VIDEO,
            self::MEDIA_TYPE_REELS,
        ], true)) {
            return true;
        }

        $path = parse_url((string) $this->media_url, PHP_URL_PATH);

        return is_string($path)
            && in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                ['mp4', 'mov', 'm4v', 'webm'],
                true
            );
    }

    public function isReels(): bool
    {
        return $this->media_product_type === self::PRODUCT_TYPE_REELS
            || $this->media_type === self::MEDIA_TYPE_REELS;
    }

    public function isCarousel(): bool
    {
        return in_array($this->media_type, [self::MEDIA_TYPE_CAROUSEL, self::MEDIA_TYPE_CAROUSEL_ALBUM], true);
    }

    /**
     * Meta API'ye gönderilecek publishing media_type değerini döndürür.
     * Publishing endpoint'i DB media_type değerinden farklı değerler kabul eder
     * (örn. VIDEO+REELS → media_type=REELS, IMAGE+STORY → media_type=STORIES).
     *
     * @see Postman koleksiyonu: "Create a video container" — media_type=REELS/STORIES
     */
    public function publishingMediaType(): string
    {
        // Carousel ayrı akış — createCarouselContainer içinde media_type=CAROUSEL gönderilir.
        if ($this->isCarousel()) {
            return self::MEDIA_TYPE_CAROUSEL;
        }

        $product = $this->media_product_type;

        // STORY → STORIES (Meta publishing media_type değeri)
        if ($product === self::PRODUCT_TYPE_STORY) {
            return self::MEDIA_TYPE_STORIES;
        }

        // REELS → REELS (Meta publishing media_type değeri)
        if ($product === self::PRODUCT_TYPE_REELS) {
            return self::MEDIA_TYPE_REELS;
        }

        // Eski tek-eksenli geriye dönük uyumluluk: media_type=REELS/STORIES
        // direkt kullanılmışsa koru.
        if ($this->media_type === self::MEDIA_TYPE_REELS) {
            return self::MEDIA_TYPE_REELS;
        }

        if ($this->media_type === self::MEDIA_TYPE_STORIES) {
            return self::MEDIA_TYPE_STORIES;
        }

        // IMAGE + FEED → IMAGE, VIDEO + FEED → VIDEO
        return $this->media_type;
    }

    /**
     * Bu post için Meta Insights endpoint'inden çekilebilecek metric
     * listesini döndürür. Media tipi/metric eşlemesi domain'de
     * (InstagramMedia hiyerarşisi) yapılır; burada yalnızca ilgili
     * InstagramMedia instance'ına delege edilir.
     *
     * @return array<int, string>
     */
    public function supportedInsightMetrics(): array
    {
        return InstagramMediaFactory::instance()
            ->make($this)
            ->supportedInsightMetrics();
    }

    protected function casts(): array
    {
        return [
            'children' => 'array',
            'is_ai_generated' => 'boolean',
            'like_count' => 'integer',
            'comments_count' => 'integer',
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'ig_media_timestamp' => 'datetime',
        ];
    }

    /**
     * Filament'in App panelindeki ->tenant() kapsamı (automatic scoping)
     * bu ilişki üzerinden çalışır.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Domain 1 ↔ Domain 2: Bu gönderinin bağladığı Link Vault ürünü.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Bu posta ait insights snapshot'ları (tarihsel trend için).
     */
    public function insights(): HasMany
    {
        return $this->hasMany(InstagramPostInsight::class);
    }

    /**
     * Story gönderileri için story_link alanı dolu mu?
     */
    public function isStory(): bool
    {
        return $this->media_product_type === self::PRODUCT_TYPE_STORY
            || $this->media_type === self::MEDIA_TYPE_STORIES;
    }

    // --- HasPublishableMedia sözleşmesi (additive; mevcut davranış korunur) ---

    public function getMediaType(): string
    {
        return $this->media_type;
    }

    public function getMediaProductType(): ?string
    {
        return $this->media_product_type;
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
