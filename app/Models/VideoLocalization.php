<?php

namespace App\Models;

use App\Domain\Video\Enums\LocalizationLanguage;
use App\Domain\Video\Enums\LocalizationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;
use Throwable;

/**
 * AI video yerelleştirme iş kaydı. Bir Content (video) için Gemini
 * analizi (timestamp'li hedef dil çevirisi + ekrandaki yazılar) ve
 * ElevenLabs TTS (hedef dil seslendirme) sonuçlarını tutar.
 *
 * Durum akışı:
 *   pending → analyzing → analyzed → voicing → completed
 *   herhangi bir adımda → failed (error_message dolu)
 *
 * status/target_language kolonları enum cast'lidir; kod boyunca
 * dize karşılaştırması yerine enum case'leri kullanılır.
 *
 * @property LocalizationStatus $status
 * @property LocalizationLanguage $target_language
 * @property array<string, mixed>|null $translation
 */
class VideoLocalization extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'team_id',
        'content_id',
        'status',
        'target_language',
        'source_language',
        'translation',
        'script',
        'audio_media_id',
        'error_message',
        'estimated_cost_usd',
        'cost_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'status' => LocalizationStatus::class,
            'target_language' => LocalizationLanguage::class,
            'translation' => 'array',
            'estimated_cost_usd' => 'decimal:4',
            'cost_breakdown' => 'array',
        ];
    }

    // --- Durum geçişleri ---------------------------------------------------

    /**
     * Kaydı verilen duruma taşır; ek alanları atomik olarak yazar.
     *
     * Production guard'ları:
     *  1. State machine — LocalizationStatus::canTransitionTo() dışındaki
     *     geçişler RuntimeException ile reddedilir.
     *  2. Optimistic lock — UPDATE ... WHERE status = <current>; eşzamanlı
     *     job'lardan biri geçişi kaçırdıysa (0 satır) exception fırlatır.
     *  3. Aynı duruma yeniden giriş (idempotent retry) serbesttir; yalnız
     *     attributes yazılır, state machine'e takılmaz.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(LocalizationStatus $status, array $attributes = []): static
    {
        $current = $this->status;

        if ($this->exists) {
            if ($current !== $status) {
                if ($current !== null && ! $current->canTransitionTo($status)) {
                    throw new RuntimeException(
                        "Yerelleştirme #{$this->id} için geçersiz durum geçişi: "
                        .$current->value.' → '.$status->value.'.'
                    );
                }
            }

            $updated = static::query()
                ->whereKey($this->getKey())
                ->where('status', $current?->value)
                ->update([...$attributes, 'status' => $status->value, 'updated_at' => now()]);

            if ($updated === 0) {
                throw new RuntimeException(
                    "Yerelleştirme #{$this->id} eşzamanlı değiştirildi (optimistic lock): "
                    .$current?->value.' → '.$status->value.'.'
                );
            }

            return $this->fresh() ?? $this;
        }

        $this->forceFill([...$attributes, 'status' => $status])->save();

        return $this;
    }

    /**
     * Başarısızlığa işaretler — zaten failed ise üzerine YAZMAZ
     * (ilk hatanın kaybolmaması için). Job'ların failed() hook'larında
     * çifte yazımı engelleyen idempotent desen.
     */
    public function markFailed(Throwable $exception): static
    {
        /** @var self|null $fresh */
        $fresh = static::query()->find($this->id);

        if ($fresh === null || $fresh->status === LocalizationStatus::Failed) {
            return $fresh ?? $this;
        }

        return $fresh->transitionTo(LocalizationStatus::Failed, [
            'error_message' => $exception->getMessage(),
        ]);
    }

    // --- Durum sorguları ---------------------------------------------------

    /**
     * Blade/Filament için durum etiketi haritası. LocalizationStatus enum
     * getLabel() artık HasLabel ile geldiği için label() çağrıları bırakıldı.
     *
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        $labels = [];

        foreach (LocalizationStatus::cases() as $status) {
            $labels[$status->value] = (string) $status->getLabel();
        }

        return $labels;
    }

    /**
     * LocalizationStatus enum'unun rengini döner (badge için).
     */
    public static function statusColor(LocalizationStatus|string $status): string
    {
        if (! $status instanceof LocalizationStatus) {
            $status = LocalizationStatus::tryFrom($status) ?? LocalizationStatus::Pending;
        }

        return match ($status) {
            LocalizationStatus::Completed => 'success',
            LocalizationStatus::Pending,
            LocalizationStatus::Analyzing,
            LocalizationStatus::Voicing => 'warning',
            LocalizationStatus::Failed => 'danger',
            default => 'gray',
        };
    }

    /**
     * Gemini segment çevirileri yüklendi mi? (seslendirilebilir mi?)
     */
    public function isAnalyzed(): bool
    {
        return $this->status->hasTranslation() && $this->translation !== null;
    }

    /**
     * Gemini "bu video zaten hedef dilde (gömülü altyazı/konuşma)" diye
     * akıllı atlama yaptı mı?
     */
    public function isSkipped(): bool
    {
        return $this->status === LocalizationStatus::Skipped;
    }

    /**
     * Akıllı atlama gerekçesi (Gemini detection.reason), yoksa null.
     */
    public function detectionReason(): ?string
    {
        return $this->translation['detection_reason'] ?? null;
    }

    /**
     * TTS üretildi mi?
     */
    public function hasAudio(): bool
    {
        return $this->status === LocalizationStatus::Completed && $this->audio_media_id !== null;
    }

    // --- İlişkiler -----------------------------------------------------

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Content, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function audioMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'audio_media_id');
    }

    // --- Yardımcılar ---------------------------------------------------

    /**
     * Yeniden tetiklemede temiz başlangıç: hedef dili günceller, önceki
     * sonucu temizler ve Pending'e çeker. State machine'i bypass eder —
     * çünkü bu bilinçli bir "sıfırla ve yeniden koş" idempotent resetidir
     * (Completed/Failed/Analyzed → Pending serbesttir, kullanıcı isteği).
     */
    public function startNewRun(LocalizationLanguage $language): static
    {
        $this->forceFill([
            'target_language' => $language,
            'status' => LocalizationStatus::Pending,
            'source_language' => null,
            'translation' => null,
            'script' => null,
            'audio_media_id' => null,
            'error_message' => null,
        ])->save();

        return $this;
    }

    /**
     * Filament Select için dil seçenekleri (value => label).
     *
     * @return array<string, string>
     */
    public static function languages(): array
    {
        return collect(LocalizationLanguage::cases())
            ->mapWithKeys(fn (LocalizationLanguage $language): array => [
                $language->value => (string) $language->getLabel(),
            ])
            ->all();
    }

    /**
     * Verilen Content'in en güncel yerelleştirme kaydı.
     */
    public static function latestFor(Content $content): ?static
    {
        /** @var static|null */
        return static::query()
            ->where('content_id', $content->id)
            ->latest('id')
            ->first();
    }

    /**
     * En güncel kayıt; hiç yoksa pending durumda yeni kayıt açılır
     * (tek video için tek aktif kayıt deseni).
     */
    public static function latestForOrCreate(
        Content $content,
        LocalizationLanguage $language = LocalizationLanguage::Turkish,
    ): static {
        return static::latestFor($content) ?? static::query()->create([
            'team_id' => $content->team_id,
            'content_id' => $content->id,
            'status' => LocalizationStatus::Pending,
            'target_language' => $language,
        ]);
    }
}
