<?php

namespace App\Console\Commands;

use App\Jobs\PublishScheduledPublication;
use App\Models\Publication;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Zamanı gelmiş Publication'ları bulur ve her biri için ayrı bir
 * PublishScheduledPublication job'ı dispatch eder. Yayın async çalışır —
 * komut kısa sürede biter, worker'lar paralel yayınlar.
 *
 * PublishScheduledInstagramPosts command'ının davranışının Publication
 * karşılığıdır; ShouldBeUnique job ID sayesinde aynı yayın iki kez
 * dispatch edilse bile kuyrukta tek job kalır.
 */
#[Signature('publications:publish-scheduled')]
#[Description('Zamanı gelmiş yayınları kuyruğa dispatch eder (async publish)')]
class PublishScheduledPublications extends Command
{
    public function handle(): int
    {
        $dueCount = 0;

        // chunkById: büyük kuyruk birikimlerinde bellek dostu tarama;
        // ShouldBeUnique zaten çift dispatch'i engeller.
        Publication::query()
            ->where('status', Publication::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(100, function ($publications) use (&$dueCount): void {
                foreach ($publications as $publication) {
                    PublishScheduledPublication::dispatch($publication);
                    $dueCount++;

                    $this->info("Kuyruğa dispatch edildi: [#{$publication->id}]");
                }
            });

        if ($dueCount === 0) {
            $this->info('Yayınlanacak zamanlanmış yayın yok.');

            return self::SUCCESS;
        }

        $this->info("Toplam {$dueCount} yayın kuyruğa dispatch edildi.");

        return self::SUCCESS;
    }
}
