<?php

namespace App\Domain\Video\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * video_localizations.status kolonundaki iş akışı durumları.
 *
 * Akış: pending → analyzing → analyzed → voicing → completed;
 * herhangi bir adımda failed'a düşebilir (error_message dolu).
 *
 * HasLabel/HasColor sayesinde Filament Select/Badge bileşenleri
 * etiket ve renkleri otomatik çözer.
 */
enum LocalizationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';

    case Analyzing = 'analyzing';

    case Analyzed = 'analyzed';

    case Voicing = 'voicing';

    case Completed = 'completed';

    case Skipped = 'skipped';

    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Bekliyor',
            self::Analyzing => 'Analiz ediliyor',
            self::Analyzed => 'Çevrildi',
            self::Voicing => 'Seslendiriliyor',
            self::Completed => 'Tamamlandı',
            self::Skipped => 'Yerelleştirme Gerekmez',
            self::Failed => 'Başarısız',
        };
    }

    /**
     * State machine: izin verilen durum geçişleri. Failed her durumdan
     * erişilebilir (erken ölüm/OOM dahil). Aynı duruma yeniden giriş
     * (idempotent retry) transitionTo'da özel olarak ele alınır.
     */
    public function canTransitionTo(self $to): bool
    {
        if ($to === self::Failed) {
            return $this !== self::Failed;
        }

        return in_array($to, match ($this) {
            self::Pending => [self::Analyzing],
            self::Analyzing => [self::Analyzed, self::Skipped],
            self::Analyzed => [self::Voicing, self::Analyzing],
            self::Voicing => [self::Completed],
            self::Completed => [self::Analyzing],
            self::Skipped => [self::Analyzing],
            self::Failed => [self::Analyzing],
        }, true);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Completed => 'success',
            self::Analyzed => 'info',
            self::Pending, self::Analyzing, self::Voicing => 'warning',
            self::Skipped => 'gray',
            self::Failed => 'danger',
        };
    }

    /**
     * Gemini/TTS çalışıyor mu? (işlem sürüyor göstergesi)
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::Pending, self::Analyzing, self::Voicing], true);
    }

    /**
     * Gemini analizi tamamlandı mı? (seslendirilebilir aşama)
     */
    public function hasTranslation(): bool
    {
        return in_array($this, [self::Analyzed, self::Voicing, self::Completed], true);
    }
}
