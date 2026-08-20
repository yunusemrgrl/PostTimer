<?php

namespace App\Listeners;

use App\Events\PostPublished;
use App\Services\NotificationService;

/**
 * Domain 4 — PostPublished event'ini dinler ve Telegram'a
 * başarılı yayın bildirimi gönderir.
 */
class NotifyPublishSuccessListener
{
    public function __construct(
        protected NotificationService $notifier,
    ) {}

    public function handle(PostPublished $event): void
    {
        $this->notifier->notifyTeam(
            $event->post->team,
            "✅ <b>Gönderi Yayınlandı</b>\n\n"
            ."Gönderi: <i>{$event->post->caption}</i>\n"
            ."Hesap: @{$event->post->team->instagramAccounts()->first()?->username}",
            'post_published',
        );
    }
}
