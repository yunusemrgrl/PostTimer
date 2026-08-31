<?php

namespace App\Jobs;

use App\Domain\Notification\Services\NotificationService;
use App\Domain\Video\Services\VideoLocalizationService;
use App\Models\VideoLocalization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Aşama 1 — Gemini video analizi + hedef dile çeviri. Uzun sürebilir
 * (indirme + multimodal analiz), bu yüzden kuyrukta çalışır.
 */
class LocalizeVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Video indirme + Gemini generateContent tek attempt'te biter.
     */
    public int $timeout = 600;

    /**
     * ShouldBeUnique kilidi: analiz sırasında ikinci tetikleme engellenir.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public VideoLocalization $localization,
    ) {}

    public function uniqueId(): string
    {
        return "localize-{$this->localization->id}";
    }

    public function handle(VideoLocalizationService $service): void
    {
        $service->analyze($this->localization);
    }

    public function failed(Throwable $exception): void
    {
        // analyze() içinden fırlayan hatalarda fail() → markFailed() zaten
        // çalışmıştır; burada yalnızca exception handler'ın yakalayamadığı
        // erken ölümler (timeout, OOM vb.) düşer. markFailed idempotenttir:
        // zaten failed olan kaydın ilk hata mesajının üzerine yazmaz.
        $this->localization->fresh()?->markFailed($exception);

        $team = $this->localization->content?->team;

        if ($team !== null) {
            app(NotificationService::class)->notifyPublishFailed(
                $team,
                'Video yerelleştirme',
                "AI çeviri başarısız: {$exception->getMessage()}",
            );
        }
    }
}
