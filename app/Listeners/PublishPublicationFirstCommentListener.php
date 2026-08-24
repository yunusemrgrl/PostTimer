<?php

namespace App\Listeners;

use App\Events\PublicationPublished;
use App\Jobs\PublishPublicationFirstComment;

/**
 * Faz B2 — PublicationPublished event'ini dinler ve ilk yorum job'unu
 * dispatch eder. PostFirstCommentListener akışının Publication karşılığıdır;
 * yorum yayından bağımsız olarak async çalışır, fail ederse gönderi etkilenmez.
 *
 * Yorum yalnızca content.first_comment doluysa ve içerik STORY (surface)
 * değilse gönderiler; STORY'de ilk yorum desteklenmez.
 */
class PublishPublicationFirstCommentListener
{
    public function handle(PublicationPublished $event): void
    {
        $content = $event->publication->content;

        if (! $content->first_comment || $content->isStory()) {
            return;
        }

        PublishPublicationFirstComment::dispatch($event->publication);
    }
}
