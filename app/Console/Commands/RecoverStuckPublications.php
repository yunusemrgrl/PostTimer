<?php

namespace App\Console\Commands;

use App\Events\PublicationPublishFailed;
use App\Models\Publication;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Worker çökmesi / OOM / kill sonrası sonsuza kadar "publishing" durumunda
 * kalan yayınları toparlar (H1 deseninin bilinen boşluğu).
 *
 * Davranış spesifikasyonu TryPost'un social:recover-stuck-posts komutundan
 * esinlenilmiştir (AGPL-3.0); kod kopyalanmamış, tek-platform mimarisine
 * uyarlanmıştır:
 *
 * - Sadece 1 saatten uzun süredir dokunulmamış `publishing` yayınları ele alınır
 *   (`updated_at` eşiği) — queue retry'ı sırasındaki canlı yayınlara dokunulmaz.
 * - Finalizasyon koşullu UPDATE ile yapılır: komut ile job retry'ı arasında yarış
 *   olursa yalnızca hâlâ publishing'de olan kayıt güncellenir.
 * - media_id doluysa yayın gerçekleşmiş demektir — ASLA FAILED'e çekilmez.
 */
#[Signature('publications:recover-stuck')]
#[Description('Publishing durumunda takılı kalmış yayınları FAILED moduna çeker')]
class RecoverStuckPublications extends Command
{
    private const STALE_THRESHOLD_MINUTES = 60;

    public function handle(): int
    {
        $threshold = now()->subMinutes(self::STALE_THRESHOLD_MINUTES);

        $stale = Publication::query()
            ->where('status', Publication::STATUS_PUBLISHING)
            ->where('updated_at', '<=', $threshold)
            ->pluck('id');

        $recovered = 0;

        foreach ($stale as $id) {
            $publication = Publication::find($id);

            if (! $publication || $publication->media_id) {
                continue;
            }

            // Koşullu finalize: bu satır hâlâ publishing'de ve hâlâ bayat mı?
            // (Komut çalışırken araya giren bir queue retry'ı updated_at'i
            // tazelemişse dokunma.)
            $finalized = Publication::query()
                ->where('id', $publication->id)
                ->where('status', Publication::STATUS_PUBLISHING)
                ->whereNull('media_id')
                ->where('updated_at', '<=', $threshold)
                ->update([
                    'status' => Publication::STATUS_FAILED,
                    'error_message' => 'publishing_timed_out',
                ]) > 0;

            if (! $finalized) {
                continue;
            }

            PublicationPublishFailed::dispatch(
                $publication->fresh(),
                'publishing_timed_out',
            );

            $recovered++;
            $this->warn("[#{$publication->id}] Publishing takılı kaldı — FAILED moduna çekildi");
        }

        if ($recovered === 0) {
            $this->info('Toparlanacak takılı yayın yok.');
        } else {
            $this->info("Toplam {$recovered} yayın kurtarıldı.");
        }

        return self::SUCCESS;
    }
}
