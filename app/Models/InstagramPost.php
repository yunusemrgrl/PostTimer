<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramPost extends Model
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

    protected $fillable = [
        'team_id',
        'ig_user_id',
        'media_type',
        'caption',
        'media_url',
        'story_link',
        'first_comment',
        'children',
        'alt_text',
        'is_ai_generated',
        'product_id',
        'container_id',
        'media_id',
        'status',
        'scheduled_at',
        'error_message',
        'published_at',
    ];

    /**
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
            self::MEDIA_TYPE_REELS => 'info',
            self::MEDIA_TYPE_VIDEO => 'warning',
            self::MEDIA_TYPE_CAROUSEL => 'success',
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
     * @param  array<int, string>  $fromStatuses
     */
    public function atomicClaim(array $fromStatuses = [self::STATUS_SCHEDULED, self::STATUS_DRAFT]): bool
    {
        return (bool) static::query()
            ->where('id', $this->id)
            ->whereIn('status', $fromStatuses)
            ->update(['status' => self::STATUS_PUBLISHING]) > 0;
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

    public function isVideo(): bool
    {
        return in_array($this->media_type, [self::MEDIA_TYPE_VIDEO, self::MEDIA_TYPE_REELS], true);
    }

    protected function casts(): array
    {
        return [
            'children' => 'array',
            'is_ai_generated' => 'boolean',
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
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
     * Story gönderileri için story_link alanı dolu mu?
     */
    public function isStory(): bool
    {
        return $this->media_type === self::MEDIA_TYPE_STORIES;
    }
}
