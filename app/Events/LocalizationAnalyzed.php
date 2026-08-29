<?php

namespace App\Events;

use App\Models\VideoLocalization;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Video yerelleştirme analizi (Gemini çevirisi) tamamlandı — script
 * incelemeye hazır. Bildirim gibi yan etkiler listener'larda yapılır.
 */
class LocalizationAnalyzed
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public VideoLocalization $localization,
    ) {}
}
