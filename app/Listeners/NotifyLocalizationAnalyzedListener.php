<?php

namespace App\Listeners;

use App\Domain\Notification\Services\NotificationService;
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
        $this->notifier->notifyTeam(
            $event->localization->team,
            "🌍 <b>Çeviri Tamamlandı</b>\n\nVideodaki konuşma {$event->localization->target_language->value} diline çevrildi.\nPanelde inceleyip seslendirmeyi başlatabilirsiniz.",
        );
    }
}
