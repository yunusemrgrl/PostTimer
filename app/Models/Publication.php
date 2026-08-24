<?php

namespace App\Models;

use Database\Factories\PublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir content'in tek bir Instagram hesabındaki yayın kaydı. Durum makinesi
 * InstagramPost ile aynıdır (draft → scheduled → publishing → published /
 * failed / flagged) ve ek olarak cancelled destekler. Publish motorunun
 * kullandığı idempotency alanları burada yaşar.
 */
class Publication extends Model
{
    /** @use HasFactory<PublicationFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHING = 'publishing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FLAGGED = 'flagged';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'content_id',
        'instagram_account_id',
        'ig_user_id',
        'status',
        'scheduled_at',
        'published_at',
        'ig_media_timestamp',
        'container_id',
        'media_id',
        'permalink',
        'error_message',
        'caption_override',
        'last_publish_attempt_at',
        'created_by',
    ];

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
            self::STATUS_CANCELLED => 'İptal Edildi',
        ];
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'ig_media_timestamp' => 'datetime',
            'last_publish_attempt_at' => 'datetime',
        ];
    }

    /**
     * Durum rozet rengi (InstagramPost::statusColor ile aynı desen).
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

    /**
     * Idempotency — Atomic Claim (InstagramPost::atomicClaim() ile aynı desen):
     * Yayını verilen kaynak durumlardan "publishing" durumuna atomik geçirir.
     * Başka bir worker aynı yayını almaya çalışırsa false döner → çift yayın engellenir.
     *
     * `publishing` de kaynak durumlardadır: geçici hata sonrası queue retry'ı
     * aynı yayını yeniden claim edebilir. Eşzamanlı çift yayını asıl engelleyen
     * servis katmanındaki Cache::lock'tur.
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
     * Hesaba özel caption yoksa content'in varsayılan caption'ı geçerlidir.
     */
    public function effectiveCaption(): ?string
    {
        return $this->caption_override ?? $this->content->caption;
    }

    /**
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /**
     * @return BelongsTo<InstagramAccount, $this>
     */
    public function instagramAccount(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
