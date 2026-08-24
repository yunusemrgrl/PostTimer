<?php

namespace App\Listeners;

use App\Events\PublicationPublished;
use App\Jobs\SyncPublicationInsights;

/**
 * Faz B1 — PublicationPublished event'ini dinler ve publication insights
 * sync job'unu dispatch eder. Eski SyncPostInsightsListener akışının
 * Publication uyarlanmış karşılığıdır; insights yayından bağımsız olarak
 * async çalışır, fail ederse gönderi veya diğer akışlar etkilenmez.
 *
 * Yalnızca media_id'si olan (gerçekten yayınlanmış) yayınlar için;
 * Carousel medya insights desteklenmediği için job içinde atlanır.
 */
class SyncPublicationInsightsListener
{
    public function handle(PublicationPublished $event): void
    {
        if (! $event->publication->media_id) {
            return;
        }

        SyncPublicationInsights::dispatch($event->publication);
    }
}
