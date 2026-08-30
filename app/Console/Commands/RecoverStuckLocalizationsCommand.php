<?php

namespace App\Console\Commands;

use App\Domain\Video\Actions\RecoverStuckLocalizations;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Worker çökmesi sonrası "analyzing"/"voicing" durumunda takılı kalan
 * video yerelleştirme kayıtlarını toparlar. Logic RecoverStuckLocalizations
 * action'ında (Beyond CRUD); bu command sadece I/O.
 */
#[Signature('localization:recover-stuck {--minutes=60 : Stale eşiği (dakika)}')]
#[Description('Analyzing/voicing durumunda takılı kalan yerelleştirmeleri toparlar')]
class RecoverStuckLocalizationsCommand extends Command
{
    public function handle(RecoverStuckLocalizations $action): int
    {
        $minutes = (int) $this->option('minutes');

        $result = $action($minutes);

        if ($result['recovered'] === 0) {
            $this->info('Toparlanacak takılı yerelleştirme yok.');

            return self::SUCCESS;
        }

        $this->info("Toplam {$result['recovered']} yerelleştirme kurtarıldı ({$result['skipped']} atlandı).");

        return self::SUCCESS;
    }
}
