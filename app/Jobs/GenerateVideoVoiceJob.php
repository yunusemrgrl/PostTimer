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
 * Aşama 2 — Türkçe script'i ElevenLabs TTS ile seslendirir ve MP3'ü
 * R2'ye yazar. TTS senkron tek istektir (dubbing polling'i YOK).
 */
class GenerateVideoVoiceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public VideoLocalization $localization,
    ) {}

    public function uniqueId(): string
    {
        return "voice-{$this->localization->id}";
    }

    public function handle(VideoLocalizationService $service): void
    {
        $service->generateVoice($this->localization);
    }

    public function failed(Throwable $exception): void
    {
        $localization = $this->localization->fresh();

        if ($localization !== null && $localization->status !== VideoLocalization::STATUS_FAILED) {
            $localization->forceFill([
                'status' => VideoLocalization::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();
        }

        $team = $this->localization->content->team;

        if ($team !== null) {
            app(NotificationService::class)->notifyPublishFailed(
                $team,
                'Video seslendirme',
                "AI seslendirme başarısız: {$exception->getMessage()}",
            );
        }
    }
}
