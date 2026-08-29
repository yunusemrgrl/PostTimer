<?php

namespace App\Events;

use App\Models\VideoLocalization;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Video yerelleştirme sesi (ElevenLabs TTS) üretildi ve R2'ye yazıldı —
 * pilot kuralı: otomatik yayına GİTMEZ, kullanıcıya sunulur.
 */
class LocalizationVoiceCompleted
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public VideoLocalization $localization,
    ) {}
}
