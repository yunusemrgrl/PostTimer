<?php

namespace App\Jobs;

use App\Models\InstagramPost;
use App\Services\InstagramInsightsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bir yayınlanmış post'un Instagram insights'larını background'da çeker.
 * PostPublished event'inden veya manuel olarak dispatch edilebilir.
 *
 * API maliyetini düşük tutmak için:
 * - ShouldBeUnique → aynı post için iki sync job'u kuyrukta olamaz
 * - media_id yoksa hiç çalışmaz (post yayınlanmamış)
 * - Carousel medya insights desteklenmiyor → service içinde atlanır
 * - Permission eksikse kontrollü RuntimeException, job failed() tetiklenir
 */
class SyncInstagramPostInsights implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public InstagramPost $post,
    ) {}

    /**
     * Unique job ID — aynı post ID için ikinci sync job'u kuyrukta kabul edilmez.
     */
    public function uniqueId(): string
    {
        return "sync-insights-{$this->post->id}";
    }

    public function handle(InstagramInsightsService $service): void
    {
        // Yalnızca yayınlanmış + media_id'si olan post'lar için çalış.
        if (! $this->post->media_id) {
            return;
        }

        $service->syncPostInsights($this->post);
    }

    /**
     * Permission/API hatalarında job fail olur ama bu post'u veya
     * diğer post'ları etkilemez. Hata log'a yazılır.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('instagram.insights.job_failed', [
            'post_id' => $this->post->id,
            'media_id' => $this->post->media_id,
            'error' => $exception->getMessage(),
        ]);
    }
}
