<?php

namespace App\Listeners;

use App\Events\PublicationPublishFailed;
use App\Services\NotificationService;

/**
 * PublicationPublishFailed event'ini dinler ve Telegram'a yayın başarısız
 * uyarısı gönderir — NotifyPublishFailedListener ile aynı kullanıcı
 * deneyimini sağlar.
 */
class NotifyPublicationPublishFailedListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(PublicationPublishFailed $event): void
    {
        $this->notifier->notifyPublishFailed(
            $event->publication->team,
            $event->publication->effectiveCaption() ?? 'Yayın #'.$event->publication->id,
            $event->error,
        );
    }
}
