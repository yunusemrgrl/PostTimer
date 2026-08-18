<?php

namespace App\Listeners;

use App\Events\PostPublishFailed;
use App\Services\NotificationService;

/**
 * Domain 4 — PostPublishFailed event'ini dinler ve Telegram'a
 * yayın başarısız uyarısı gönderir.
 */
class NotifyPublishFailedListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(PostPublishFailed $event): void
    {
        $this->notifier->notifyPublishFailed(
            $event->post->team,
            $event->post->caption ?? 'Gönderi #'.$event->post->id,
            $event->error,
        );
    }
}
