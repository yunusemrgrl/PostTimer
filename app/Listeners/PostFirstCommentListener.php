<?php

namespace App\Listeners;

use App\Events\PostPublished;
use App\Jobs\PostFirstComment;

/**
 * Domain 2 — PostPublished event'ini dinler ve ilk yorum job'ını dispatch eder.
 * Yorum, yayından bağımsız olarak async çalışır — fail ederse gönderi etkilenmez.
 */
class PostFirstCommentListener
{
    public function handle(PostPublished $event): void
    {
        if (! $event->post->first_comment || $event->post->isStory()) {
            return;
        }

        PostFirstComment::dispatch($event->post);
    }
}
