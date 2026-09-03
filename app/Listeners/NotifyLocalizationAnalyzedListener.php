<?php

namespace App\Listeners;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Video\Enums\LocalizationStatus;
use App\Events\LocalizationAnalyzed;

/**
 * LocalizationAnalyzed event'ini dinler ve takıma Telegram bildirimi
 * gönderir — çeviri panelde incelenip seslendirilebilir.
 */
class NotifyLocalizationAnalyzedListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(LocalizationAnalyzed $event): void
    {
        $localization = $event->localization;

        // Akıllı atlama: video zaten hedef dildeydi (gömülü altyazı vb.)
        // — çeviri/seslendirme yapılmadı, kullanıcıya bunu bildir.
        if ($localization->status === LocalizationStatus::Skipped) {
            $reason = $localization->detectionReason();

            $this->notifier->notifyTeam(
                $localization->team,
                "✅ <b>Yerelleştirme Gerekmez</b>\n\nVideo zaten {$localization->target_language->value} dilinde izlenebilir durumda — çeviri ve seslendirme atlandı.\n".($reason !== null ? "Gerekçe: {$reason}\n" : '').'Yayın planlamaya devam edebilirsiniz.',
            );

            return;
        }

        $this->notifier->notifyTeam(
            $localization->team,
            "🌍 <b>Çeviri Tamamlandı</b>\n\nVideodaki konuşma {$localization->target_language->value} diline çevrildi.\nPanelde inceleyip seslendirmeyi başlatabilirsiniz.",
        );
    }
}
