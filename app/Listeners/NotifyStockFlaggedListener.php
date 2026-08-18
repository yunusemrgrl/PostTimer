<?php

namespace App\Listeners;

use App\Events\PostFlagged;
use App\Services\NotificationService;

/**
 * Domain 4 — PostFlagged event'ini dinler ve Telegram'a
 * stok/fiyat uyarısı gönderir.
 */
class NotifyStockFlaggedListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(PostFlagged $event): void
    {
        $this->notifier->notifyStockFlagged(
            $event->post->team,
            $event->post->caption ?? 'Gönderi #'.$event->post->id,
            $event->reason,
        );
    }
}
