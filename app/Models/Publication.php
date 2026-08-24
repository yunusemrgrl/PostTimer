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
     * Hata kategorileri — kalıcı kolon YERİNE error_message'ten türetilir
     * (şema değişikliği gerektirmeden kullanıcı-dostu sınıflandırma).
     * TryPost'un 13-değerli ErrorCategory enum'unun tek-platform
     * sadeleştirmesidir.
     */
    public const ERROR_CATEGORY_QUOTA = 'quota';

    public const ERROR_CATEGORY_TIMEOUT = 'timeout';

    public const ERROR_CATEGORY_TOKEN = 'token_expired';

    public const ERROR_CATEGORY_API = 'api_error';

    public const ERROR_CATEGORY_UNKNOWN = 'unknown';

    /**
     * @return array<string, string>
     */
    public static function errorCategories(): array
    {
        return [
            self::ERROR_CATEGORY_QUOTA => 'Kota doldu',
            self::ERROR_CATEGORY_TIMEOUT => 'Zaman aşımı',
            self::ERROR_CATEGORY_TOKEN => 'Bağlantı/jeton sorunu',
            self::ERROR_CATEGORY_API => 'API hatası',
            self::ERROR_CATEGORY_UNKNOWN => 'Bilinmeyen hata',
        ];
    }

    public static function errorCategoryColor(string $category): string
    {
        return match ($category) {
            self::ERROR_CATEGORY_QUOTA, self::ERROR_CATEGORY_TOKEN => 'warning',
            self::ERROR_CATEGORY_TIMEOUT, self::ERROR_CATEGORY_API => 'danger',
            default => 'gray',
        };
    }

    /**
     * Hata mesajından kategori türetir. Sıra önemlidir: en spesifik
     * desen önce eşleşir.
     */
    public function errorCategory(): string
    {
        $message = mb_strtolower((string) $this->error_message);

        return match (true) {
            $message === '' => self::ERROR_CATEGORY_UNKNOWN,
            str_contains($message, 'publishing_timed_out') => self::ERROR_CATEGORY_TIMEOUT,
            str_contains($message, 'limiti doldu') => self::ERROR_CATEGORY_QUOTA,
            str_contains($message, 'erişilemedi')
                || str_contains($message, 'jeton')
                || str_contains($message, 'token') => self::ERROR_CATEGORY_TOKEN,
            default => self::ERROR_CATEGORY_API,
        };
    }

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
        'carousel_child_container_ids',
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
            'carousel_child_container_ids' => 'array',
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
