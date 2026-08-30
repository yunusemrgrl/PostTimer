<?php

namespace App\Jobs;

use App\Domain\Instagram\Services\InstagramInsightsService;
use App\Models\Publication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Faz B1 — Yayınlanmış bir Publication'un Instagram insights'larını
 * background'da çeker. SyncInstagramPostInsights job'unın Publication
 * uyarlanmış karşılığıdır.
 *
 * API maliyetini düşük tutmak için:
 * - ShouldBeUnique → aynı yayın için iki sync job'u kuyrukta olamaz
 * - media_id yoksa hiç çalışmaz (yayın yayınlanmamış)
 * - Carousel medya insights desteklenmiyor → service içinde atlanır
 * - Permission eksikse kontrollü RuntimeException, job failed() tetiklenir
 */
class SyncPublicationInsights implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public Publication $publication,
    ) {}

    /**
     * Unique job ID — aynı publication ID için ikinci sync job'u kuyrukta kabul edilmez.
     */
    public function uniqueId(): string
    {
        return "sync-insights-publication-{$this->publication->id}";
    }

    public function handle(InstagramInsightsService $service): void
    {
        // Yalnızca yayınlanmış + media_id'si olan yayınlar için çalış.
        if (! $this->publication->media_id) {
            return;
        }

        $service->syncPublication($this->publication);
    }

    /**
     * Permission/API hatalarında job fail olur ama bu yayın'i veya
     * diğer yayınları etkilemez. Hata log'a yazılır.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('instagram.insights.publication_job_failed', [
            'publication_id' => $this->publication->id,
            'media_id' => $this->publication->media_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
