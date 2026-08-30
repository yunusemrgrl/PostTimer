<?php

namespace App\Jobs;

use App\Domain\Publishing\Services\PublicationPublishingService;
use App\Events\PublicationPublishFailed;
use App\Models\Publication;
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

    /**
     * VIDEO akışı container status'unu Meta'nın önerisiyle 1 dk aralıkla
     * en fazla 5 kez poll eder (≈5 dk) + upload süresi. 85 sn'lik eski
     * değer video yayını worker'ı ortadan kesiyor ve yayın recover-stuck
     * tarafından haksızca FAILED'e çekiliyordu (TryPost'un 900 sn
     * "download/upload + poll headroom" gerekçesinin video ayakları).
     */
    public int $timeout = 420;

    /**
     * ShouldBeUnique kilidinin ne kadar süre tutulacağı. Job'un en kötü
     * ihtimalle tamamlanma süresi: (tries × timeout) + sum(backoff) + pay
     * = (3 × 420) + 450 + pay → 30 dk güvenli üst sınır.
     */
    public int $uniqueFor = 1800;

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
