<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\Actions\EstimateLocalizationCost;
use App\Domain\Video\Enums\LocalizationStatus;
use App\Domain\Video\Services\Contracts\TextToSpeechProvider;
use App\Domain\Video\Services\Contracts\VideoTranslationProvider;
use App\Events\LocalizationAnalyzed;
use App\Events\LocalizationVoiceCompleted;
use App\Filament\Curator\TenantPathGenerator;
use App\Models\Media;
use App\Models\VideoLocalization;
use Awcodes\Curator\Facades\Curator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Video yerelleştirme orkestratörü. Render/mux YOK:
 *
 *   R2'deki video → Gemini (timestamp'li çeviri) → script →
 *   ElevenLabs TTS → MP3 → R2 (Curator Media)
 *
 * İki bağımsız aşama: analyze() kuyruktan LocalizeVideoJob ile,
 * generateVoice() GenerateVideoVoiceJob ile çağrılır — böylece kullanıcı
 * çeviriyi inceledikten sonra seslendirmeyi tetikleyebilir.
 *
 * Idempotency sözleşmesi:
 *  - analyze(): status=Analyzing iken gelen duplicate dispatch sessizce
 *    atlanır (job retry/çift dispatch'te Gemini'ye ikinci ücretli çağrı YOK).
 *  - generateVoice(): sesi üretilmiş kayıt (hasAudio) tekrar seslendirilmez;
 *    MP3 deterministik path'e yazılır (id + script hash) — retry'ta
 *    duplicate Media kaydı oluşmaz, eskisi temizlenir.
 */
class VideoLocalizationService
{
    public function __construct(
        protected VideoTranslationProvider $gemini,
        protected TextToSpeechProvider $tts,
        protected EstimateLocalizationCost $estimateCost,
    ) {}

    /**
     * Aşama 1: Videoyu Gemini'ye verir; timestamp'li segment çevirileri +
     * ekrandaki yazıları persist eder ve anlatım script'ini oluşturur.
     */
    public function analyze(VideoLocalization $localization): void
    {
        $localization = $this->refresh($localization);

        $content = $localization->content;

        if (! $content->isVideo()) {
            throw new RuntimeException(
                "Yerelleştirme: Content #{$content->id} bir video değil (type={$content->type})."
            );
        }

        if ($content->media_url === null || $content->media_url === '') {
            throw new RuntimeException(
                "Yerelleştirme: Content #{$content->id} için media_url boş."
            );
        }

        // Idempotency: worker ölümü/çift dispatch sonrası ikinci Gemini
        // çağrısını engelle (ücretli + üstüne yazma riski).
        if ($localization->status === LocalizationStatus::Analyzing) {
            $this->log($localization, 'localization.analyze_skipped', [
                'reason' => 'already_analyzing',
            ]);

            return;
        }

        // transitionTo fresh instance dondurur — stale model ile devam
        // etmemek icin yakalanmali (optimistic lock bayat status okur).
        $localization = $localization->transitionTo(LocalizationStatus::Analyzing, [
            'error_message' => null,
        ]);

        try {
            $translation = $this->gemini->analyze(
                (string) $content->media_url,
                $localization->target_language->value,
            );

            $localization = $localization->transitionTo(LocalizationStatus::Analyzed, [
                'source_language' => $translation['source_language'],
                'translation' => $translation,
                'script' => $this->buildScript($translation),
                'error_message' => null,
            ]);

            // Maliyet takibi: Gemini analizi (TTS henüz yok).
            $cost = ($this->estimateCost)($localization, includeTts: false);
            $localization->update([
                'estimated_cost_usd' => $cost->total(),
                'cost_breakdown' => $cost->toArray(),
            ]);

            $this->log($localization, 'localization.analyzed', [
                'source_language' => $translation['source_language'],
                'segment_count' => count($translation['segments']),
                'on_screen_text_count' => count($translation['on_screen_text']),
            ]);

            // Yan etki bildirimi: Notification domain'i event'i dinler.
            LocalizationAnalyzed::dispatch($localization);
        } catch (Throwable $exception) {
            $this->fail($localization, 'Analiz başarısız', $exception);

            throw $exception;
        }
    }

    /**
     * Aşama 2: Script'i ElevenLabs ile seslendirir, MP3'ü R2'ye yazar.
     * Pilot kuralı: otomatik yayına GİTMEZ — audio + script kullanıcıya
     * sunulur, final montaj elle/Edits'te yapılır.
     */
    public function generateVoice(VideoLocalization $localization): void
    {
        $localization = $this->refresh($localization);

        if (! $localization->isAnalyzed()) {
            throw new RuntimeException(
                "Yerelleştirme #{$localization->id} henüz analiz edilmedi — önce Gemini analizi gerekli."
            );
        }

        // Idempotency: sesi üretilmiş kayıt tekrar seslendirilmez
        // (ücretli TTS + duplicate Media kaydı engellenir).
        if ($localization->hasAudio()) {
            $this->log($localization, 'localization.voice_skipped', [
                'reason' => 'already_completed',
                'audio_media_id' => $localization->audio_media_id,
            ]);

            return;
        }

        $localization = $localization->transitionTo(LocalizationStatus::Voicing, [
            'error_message' => null,
        ]);

        try {
            /** @var string $script */
            $script = $localization->script ?? $this->buildScript((array) $localization->translation);

            $audioBytes = $this->tts->synthesize($script);

            $audioMedia = $this->persistAudio($localization, $audioBytes, $script);

            $localization = $localization->transitionTo(LocalizationStatus::Completed, [
                'script' => $script,
                'audio_media_id' => $audioMedia->id,
                'error_message' => null,
            ]);

            // Maliyet takibi: Gemini + ElevenLabs TTS (kümülatif toplam).
            $cost = ($this->estimateCost)($localization, includeTts: true);
            $localization->update([
                'estimated_cost_usd' => $cost->total(),
                'cost_breakdown' => $cost->toArray(),
            ]);

            $this->log($localization, 'localization.completed', [
                'audio_media_id' => $audioMedia->id,
                'script_length' => mb_strlen($script),
                'estimated_cost_usd' => $cost->total(),
                'note' => 'Pilot: ses + script kullanıcıya sunuldu; video render/mux yapılmadı.',
            ]);

            // Yan etki bildirimi: Notification domain'i event'i dinler.
            LocalizationVoiceCompleted::dispatch($localization);
        } catch (Throwable $exception) {
            $this->fail($localization, 'Seslendirme başarısız', $exception);

            throw $exception;
        }
    }

    /**
     * Segment çevirilerini sıralı tek anlatım metnine birleştirir.
     * Ekrandaki yazılar OKUNMAZ — onlar creator'a görsel düzeltme için sunulur.
     *
     * @param  array<string, mixed>  $translation
     */
    protected function buildScript(array $translation): string
    {
        $lines = [];

        foreach ((array) ($translation['segments'] ?? []) as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $line = trim((string) ($segment['translation'] ?? ''));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            throw new RuntimeException('Script oluşturulamadı: çevrilmiş segment yok.');
        }

        return implode("\n", $lines);
    }

    /**
     * Ses bytes'ını R2'ye (Curator diskine) yazar. Protected: Storage::fake
     * testlerinde mock'lanabilmesi için (eski dubbing job deseni).
     */
    protected function persistAudio(VideoLocalization $localization, string $audioBytes): Media
    {
        $content = $localization->content;
        $diskName = Curator::getDiskName();
        $disk = Storage::disk($diskName);

        $directory = TenantPathGenerator::pathForTeam($content->team, 'localization');
        $filename = 'localization-'.$localization->id.'-'.md5($script);
        $ext = 'mp3';
        $path = rtrim($directory, '/').'/'.$filename.'.'.$ext;

        /** @var Media|null $media */
        $media = Media::query()
            ->where('disk', $diskName)
            ->where('path', $path)
            ->first();

        if ($media === null) {
            $media = Media::query()->create([
                'disk' => $diskName,
                'directory' => $directory,
                'visibility' => 'public',
                'name' => $filename,
                'path' => $path,
                'ext' => $ext,
                'type' => 'audio/mpeg',
                'size' => strlen($audioBytes),
                'team_id' => $content->team_id,
            ]);
        }

        $disk->put($path, $audioBytes, [
            'visibility' => 'public',
            'ContentType' => 'audio/mpeg',
        ]);

        $this->deleteOrphanAudio($localization, $media->id);

        return $media;
    }

    /**
     * Yerine yeni ses üretilmiş, hiçbir kayıt tarafından referans edilmeyen
     * eski MP3 Media kaydını (ve R2 dosyasını) temizler.
     */
    protected function deleteOrphanAudio(VideoLocalization $localization, int $currentAudioMediaId): void
    {
        $previousId = $localization->getOriginal('audio_media_id');

        if ($previousId === null || (int) $previousId === $currentAudioMediaId) {
            return;
        }

        /** @var Media|null $orphan */
        $orphan = Media::query()->find($previousId);

        if ($orphan === null || VideoLocalization::query()->where('audio_media_id', $orphan->id)->exists()) {
            return;
        }

        Storage::disk($orphan->disk)->delete($orphan->path);
        $orphan->delete();
    }

    protected function fail(VideoLocalization $localization, string $stage, Throwable $exception): void
    {
        // markFailed idempotenttir: zaten failed ise üzerine YAZMAZ ve
        // job failed() hook'u ile çift yazım/çift bildirim olmaz.
        $localization->markFailed($exception);

        $this->log($localization, 'localization.failed', [
            'stage' => $stage,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Kuyruk gecikmeleri sırasında kaydın güncel halini yükler (analyzed
     * gelen bir translation'ın üzerine yazmayı engeller).
     */
    protected function refresh(VideoLocalization $localization): VideoLocalization
    {
        /** @var VideoLocalization|null $fresh */
        $fresh = VideoLocalization::query()->find($localization->id);

        return $fresh ?? $localization;
    }

    /**
     * PublishFlowLogger deseni: publish kanalına yapılandırılmış log.
     *
     * @param  array<string, mixed>  $extra
     */
    private function log(VideoLocalization $localization, string $event, array $extra = []): void
    {
        Log::channel('publish')->info($event, $extra + [
            'video_localization_id' => $localization->id,
            'content_id' => $localization->content_id,
            'team_id' => $localization->team_id,
            'target_language' => $localization->target_language instanceof LocalizationLanguage
                ? $localization->target_language->value
                : $localization->target_language,
        ]);
    }
}
