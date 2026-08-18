<?php

namespace App\Jobs;

use App\Events\PostPublishFailed;
use App\Models\InstagramPost;
use App\Services\PublishInstagramPostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Domain 2 — Zamanlanmış bir gönderiyi queue üzerinden yayınlar.
 *
 * Idempotency:
 * - ShouldBeUnique + uniqueId() → aynı post için iki job kuyrukta olamaz
 * - Service katmanında atomic claim + container resume + media_id guard
 * - failed() → event dispatch (Telegram uyarısı)
 * - tries=3, backoff=[60,180,300] → aşamalı retry
 */
class PublishScheduledPost implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 120;

    public function __construct(
        public InstagramPost $post,
    ) {}

    /**
     * Unique job ID — aynı post ID için ikinci job kuyrukta kabul edilmez.
     */
    public function uniqueId(): string
    {
        return "publish-post-{$this->post->id}";
    }

    public function handle(PublishInstagramPostService $service): void
    {
        $service->publish($this->post);
    }

    /**
     * Tüm retry'lar tükendiğinde çağrılır — Telegram uyarısı tetiklenir.
     */
    public function failed(Throwable $exception): void
    {
        PostPublishFailed::dispatch($this->post->fresh(), $exception->getMessage());
    }
}
