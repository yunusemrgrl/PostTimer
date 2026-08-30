<?php

namespace App\Domain\Video\Actions;

use App\Domain\Video\Enums\LocalizationStatus;
use App\Models\VideoLocalization;
use Illuminate\Support\Facades\Log;

/**
 * Worker çökmesi / OOM / kill sonrası sonsuza kadar "analyzing" veya
 * "voicing" durumunda kalan yerelleştirme kayıtlarını toparlar.
 *
 * PublicationsRecoverStuck deseninin Beyond CRUD action versiyonu:
 * logic burada, command sadece I/O (çağırır + bilgi yazdırır).
 *
 * Davranış:
 *  - Sadece eşiği aşan süredir dokunulmamış analyzing/voicing kayıtları.
 *  - Koşullu UPDATE (optimistic lock) — komut ile queue retry yarışı olursa
 *    yalnızca hâlâ analyzing/voicing'de olan kayıt güncellenir.
 *  - analyzing → failed (çeviri yapılmamış, retry edilebilir).
 *  - voicing → completed (ses üretilmemiş olabilir ama analiz tamam —
 *    kullanıcı seslendirmeyi yeniden tetikleyebilir).
 */
final class RecoverStuckLocalizations
{
    private const STALE_THRESHOLD_MINUTES = 60;

    /**
     * @return array{recovered: int, skipped: int}
     */
    public function __invoke(int $thresholdMinutes = self::STALE_THRESHOLD_MINUTES): array
    {
        $threshold = now()->subMinutes($thresholdMinutes);

        $stuck = VideoLocalization::query()
            ->whereIn('status', [LocalizationStatus::Analyzing->value, LocalizationStatus::Voicing->value])
            ->where('updated_at', '<=', $threshold)
            ->pluck('id');

        $recovered = 0;
        $skipped = 0;

        foreach ($stuck as $id) {
            $record = VideoLocalization::find($id);

            if ($record === null) {
                continue;
            }

            // Koşullu finalize: bu satır hâlâ analyzing/voicing'de ve hâlâ bayat mı?
            $finalized = VideoLocalization::query()
                ->where('id', $record->id)
                ->whereIn('status', [LocalizationStatus::Analyzing->value, LocalizationStatus::Voicing->value])
                ->where('updated_at', '<=', $threshold)
                ->update($this->finalState($record)) > 0;

            if (! $finalized) {
                $skipped++;

                continue;
            }

            $recovered++;
            Log::channel('publish')->info('localization.recovered_stuck', [
                'video_localization_id' => $record->id,
                'previous_status' => $record->status->value,
                'content_id' => $record->content_id,
                'team_id' => $record->team_id,
            ]);
        }

        return ['recovered' => $recovered, 'skipped' => $skipped];
    }

    /**
     * analyzing → failed (çeviri yok, yeniden denenebilir).
     * voicing → completed (analiz tamam, seslendirme yeniden tetiklenebilir).
     *
     * @return array<string, mixed>
     */
    private function finalState(VideoLocalization $record): array
    {
        if ($record->status === LocalizationStatus::Voicing) {
            return [
                'status' => LocalizationStatus::Completed->value,
                'error_message' => 'voicing_timed_out_voice_retryable',
            ];
        }

        return [
            'status' => LocalizationStatus::Failed->value,
            'error_message' => 'analyzing_timed_out',
        ];
    }
}
