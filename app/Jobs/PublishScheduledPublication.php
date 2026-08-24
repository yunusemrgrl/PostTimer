<?php

namespace App\Jobs;

use App\Events\PublicationPublishFailed;
use App\Models\Publication;
use App\Services\PublicationPublishingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Zamanlanmış bir Publication'ı queue üzerinden yayınlar.
 * PublishScheduledPost job'ının Publication'a uyarlanmış hâlidir.
 *
 * Idempotency:
 * - ShouldBeUnique + uniqueId() → aynı yayın için iki job kuyrukta olamaz
 * - handle() başında durum koruması: cancelled/published/flagged yayınlara dokunulmaz
 * - Service katmanında atomic claim + container resume + media_id guard
 * - tries=3, backoff [30,120,300] → aşamalı retry
 *
 * NOT: Bu adımda event dispatch YOKTUR — publication event'leri Faz A4'te
 * tasarlanacak (mevcut Post* event'leri InstagramPost tiplidir).
 */
class PublishScheduledPublication implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Aşamalı (exponential) retry: 30 sn → 2 dk → 5 dk. Kota dolması gibi
     * uzun süreli koşullarda ilk iki hızlı deneme boşa harcanmaz.
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public int $timeout = 85;

    /**
     * ShouldBeUnique kilidinin ne kadar süre tutulacağı. Job'un en kötü
     * ihtimalle tamamlanma süresi: (tries × timeout) + sum(backoff) + pay
     * = (3 × 85) + 450 + pay ≈ 15 dk. Bu olmadan varsayılan davranış,
     * kilidi job tamamlanana/failed() tetiklenene kadar süresiz tutar —
     * worker temiz ölürse kilit takılı kalabilir ve yayın bir daha hiç
     * dispatch edilemez.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public Publication $publication,
    ) {}

    /**
     * Unique job ID — aynı yayın ID için ikinci job kuyrukta kabul edilmez.
     */
    public function uniqueId(): string
    {
        return "publish-publication-{$this->publication->id}";
    }

    /**
     * Yayın hâlâ publish edilebilir durumda mı?
     * cancelled/published/flagged (ve beklenmedik diğer durumlar) atlanır.
     */
    public function canBePublished(Publication $publication): bool
    {
        return in_array($publication->status, [
            Publication::STATUS_SCHEDULED,
            Publication::STATUS_DRAFT,
            Publication::STATUS_PUBLISHING,
        ], true);
    }

    public function handle(PublicationPublishingService $service): void
    {
        // Durum koruması: iptal edilmiş / yayınlanmış / uyarılmış yayına dokunma.
        if (! $this->canBePublished($this->publication)) {
            return;
        }

        $service->publish($this->publication);
    }

    /**
     * Tüm retry'lar tükendiğinde çağrılır (gerçek kalıcı hata).
     * H1 deseni: yalnızca burada kalıcı FAILED'e geçilir; media_id varsa
     * yayın başarıyla gerçekleşmiş demektir, üzerine yazılmaz.
     */
    public function failed(Throwable $exception): void
    {
        // Çift failed() çağrısına ve tekrar uyarıya karşı koruma:
        // zaten FAILED işaretlenmişse event tekrar gönderilmez.
        if ($this->publication->exists
            && ! $this->publication->media_id
            && $this->publication->status !== Publication::STATUS_FAILED
        ) {
            $this->publication->forceFill([
                'status' => Publication::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            // Kalıcı başarısızlıkta tek seferlik event — queue retry'ları
            // sırasında her exception için event gönderilmez.
            PublicationPublishFailed::dispatch(
                $this->publication->fresh(),
                $exception->getMessage(),
            );
        }
    }
}
