<?php

namespace App\Listeners;

use App\Domain\Notification\Services\NotificationService;
use App\Events\PublicationPublished;

/**
 * PublicationPublished event'ini dinler ve Telegram'a başarılı yayın
 * bildirimi gönderir — NotifyPublishSuccessListener ile aynı kullanıcı
 * deneyimini sağlar.
 */
class NotifyPublicationPublishedListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(PublicationPublished $event): void
    {
        $publication = $event->publication;

        $this->notifier->notifyTeam(
            $publication->team,
            "✅ <b>Gönderi Yayınlandı</b>\n\n"
            ."Gönderi: <i>{$publication->effectiveCaption()}</i>\n"
            .'Hesap: @'.$publication->instagramAccount?->username,
        );
    }
}
