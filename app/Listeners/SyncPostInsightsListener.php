<?php

namespace App\Listeners;

use App\Events\PostPublished;
use App\Jobs\SyncInstagramPostInsights;

/**
 * Domain 4 — PostPublished event'ini dinler ve insights sync job'unu
 * dispatch eder. Insights, yayından bağımsız olarak async çalışır —
 * fail ederse gönderi veya diğer akışlar etkilenmez.
 *
 * Carousel medya insights desteklenmediği için job içinde atlanır
 * (service katmanı boş metric listesi döndürür).
 */
class SyncPostInsightsListener
{
    public function handle(PostPublished $event): void
    {
        // Yalnızca media_id'si olan (gerçekten yayınlanmış) post'lar için.
        if (! $event->post->media_id) {
            return;
        }

        SyncInstagramPostInsights::dispatch($event->post);
    }
}
