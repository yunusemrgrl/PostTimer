<?php

namespace App\Jobs;

use App\Domain\Instagram\Services\InstagramPublishingService;
use App\Domain\Notification\Services\NotificationService;
use App\Models\Publication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Faz B2 — Yayınlanmış bir yayın'ın content'e bağlı varsayılan ilk yorumu
 * Instagram hesabtun atar. PostFirstComment job'unın Publication karşılığıdır;
 * yorum publish'ten bağımsız async çalışır — fail ederse gönderi hâlâ
 * yayınlanmış sayılır, sadece yorum retry edilir.
 *
 * Idempotency:
 * - ShouldBeUnique → aynı yayın için iki yorum job'u kuyrukta olamaz
 * - media_id kontrolü → yayın henüz yayınlanmamışse atlanır
 * - tries=3, backoff → geçici API hatası için retry
 * - STORY yüzeyi (surface) ilk yorum desteklenmez
 */
class PublishPublicationFirstComment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public Publication $publication,
    ) {}

    /**
     * Unique job ID — aynı publication için ikinci yorum job'u kuyrukta kabul edilmez.
     */
    public function uniqueId(): string
    {
        return "first-comment-publication-{$this->publication->id}";
    }

    public function handle(): void
    {
        $content = $this->publication->content;

        // Yorum yalnızca content first_comment doluysa ve STORY değilse.
        if (! $content->first_comment || $content->isStory()) {
            return;
        }

        // Yayın henüz yayınlanmamışse (media_id yok) yorum atılmasın.
        if (! $this->publication->media_id) {
            return;
        }

        // Hesap, publication'un KENDİ InstagramAccount'ıdır (backup/global yok).
        $account = $this->publication->instagramAccount;

        if (! $account) {
            return;
        }

        $instagram = InstagramPublishingService::forAccount($account);

        $instagram->createComment($this->publication->media_id, $content->first_comment);
    }

    /**
     * Yorum hata durumunda mevcut job/error handling desenini koru:
     * kalıcı başarısızlıkta NotificationService üzerinden gönderimi gönderir.
     */
    public function failed(Throwable $exception): void
    {
        $content = $this->publication->content;

        app(NotificationService::class)->notifyPublishFailed(
            $this->publication->team,
            $content->caption ?? 'Gönderi #'.$this->publication->id,
            "İlk yorum atılamadı: {$exception->getMessage()}",
        );
    }
}
