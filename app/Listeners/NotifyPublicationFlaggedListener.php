<?php

namespace App\Listeners;

use App\Events\PublicationFlagged;
use App\Services\NotificationService;

/**
 * PublicationFlagged event'ini dinler ve Telegram'a stok/fiyat uyarısı
 * gönderir — NotifyStockFlaggedListener ile aynı kullanıcı deneyimini sağlar.
 */
class NotifyPublicationFlaggedListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(PublicationFlagged $event): void
    {
        $this->notifier->notifyStockFlagged(
            $event->publication->team,
            $event->publication->effectiveCaption() ?? 'Yayın #'.$event->publication->id,
            $event->reason,
        );
    }
}
