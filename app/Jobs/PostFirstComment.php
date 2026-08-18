<?php

namespace App\Jobs;

use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Services\InstagramPublishingService;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Domain 2 — Yayınlanmış bir gönderiye otomatik ilk yorumu atar.
 * PublishScheduledPost job'ından ayrı çalışır — yorum fail ederse
 * gönderi hâlâ yayınlanmış sayılır, sadece yorum retry edilir.
 *
 * Idempotency:
 * - ShouldBeUnique → aynı post için iki yorum job'u kuyrukta olamaz
 * - media_id kontrolü → yorum zaten atılmış olabilir (comment_id track edilebilir)
 * - tries=3, backoff → geçici API hatası için retry
 */
class PostFirstComment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public InstagramPost $post,
    ) {}

    public function uniqueId(): string
    {
        return "first-comment-{$this->post->id}";
    }

    public function handle(NotificationService $notifier): void
    {
        if (! $this->post->first_comment || $this->post->isStory()) {
            return;
        }

        if (! $this->post->media_id) {
            return;
        }

        $account = InstagramAccount::query()
            ->where('team_id', $this->post->team_id)
            ->where('ig_user_id', $this->post->ig_user_id)
            ->first();

        if (! $account) {
            return;
        }

        $instagram = InstagramPublishingService::forAccount($account);

        $instagram->createComment($this->post->media_id, $this->post->first_comment);
    }

    public function failed(Throwable $exception): void
    {
        app(NotificationService::class)->notifyPublishFailed(
            $this->post->team,
            $this->post->caption ?? 'Gönderi #'.$this->post->id,
            "İlk yorum atılamadı: {$exception->getMessage()}",
        );
    }
}
