<?php

namespace App\Listeners;

use App\Domain\Notification\Services\NotificationService;
use App\Events\LocalizationVoiceCompleted;

/**
 * LocalizationVoiceCompleted event'ini dinler ve takıma Telegram
 * bildirimi gönderir — ses panelde önizlenebilir.
 */
class NotifyLocalizationVoiceCompletedListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(LocalizationVoiceCompleted $event): void
    {
        $this->notifier->notifyTeam(
            $event->localization->team,
            "🎙️ <b>Türkçe Ses Hazır</b>\n\nSeslendirme tamamlandı, panelde önizleyebilirsiniz.",
        );
    }
}
