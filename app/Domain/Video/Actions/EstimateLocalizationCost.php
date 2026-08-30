<?php

namespace App\Domain\Video\Actions;

use App\Domain\Video\Data\CostEstimate;
use App\Models\VideoLocalization;

/**
 * Bir yerelleştirme kaydının tahmini AI maliyetini hesaplar (Beyond CRUD
 * Action: use-case başına tek sınıf, `__invoke`).
 *
 * Fiyatlandırma (2026-08, config/elevenlabs.php + config/gemini.php'deki
 * modellere göre; gerçek API fiyat değişirse config'ten sürdürülür):
 *
 *  - Gemini 2.5 Flash: video analizi ~$0.30 / 1M token. Bir video saniyesi
 *    yaklaşık 833 token (multimodal) → ~$0.000250 / saniye.
 *  - ElevenLabs Flash v2.5: ~$0.06 / 1K karakter (multilingual_v2 ~$0.22).
 *
 * TTS maliyeti script karakter sayısı × model fiyatı; Gemini maliyeti
 * çevirilen segmentlerin toplam süresine (son segment end - ilk start)
 * dayanır — boş sessizlikleri hariç tutmak yerine toplam video süresini
 * baz alırız (Gemini tüm videoyu işler).
 */
final class EstimateLocalizationCost
{
    public function __invoke(
        VideoLocalization $localization,
        ?string $ttsModelId = null,
        bool $includeTts = true,
    ): CostEstimate {
        $translation = $localization->translation ?? [];

        // --- Gemini: video süresine göre (config: cost_per_video_second) ---
        $segments = $translation['segments'] ?? [];
        $geminiSeconds = 0.0;
        if (filled($segments)) {
            $starts = array_column($segments, 'start');
            $ends = array_column($segments, 'end');
            if (! empty($ends) && ! empty($starts)) {
                $geminiSeconds = max(0.0, (float) end($ends) - (float) reset($starts));
            }
        }
        $geminiCost = round(
            $geminiSeconds * (float) config('gemini.cost_per_video_second', 0.000250),
            4,
        );

        // --- ElevenLabs: script karakter sayısı × model fiyatı (config) ---
        $ttsCost = 0.0;
        $charCount = 0;
        $modelId = $ttsModelId ?? (string) config('elevenlabs.tts.model_id', 'eleven_flash_v2_5');

        if ($includeTts && filled($localization->script)) {
            $charCount = mb_strlen($localization->script);
            $per1k = str_contains($modelId, 'multilingual')
                ? (float) config('elevenlabs.tts.cost_per_1k_multilingual', 0.22)
                : (float) config('elevenlabs.tts.cost_per_1k_flash', 0.06);
            $ttsCost = round(($charCount / 1000) * $per1k, 4);
        }

        return new CostEstimate(
            geminiCost: $geminiCost,
            ttsCost: $ttsCost,
            breakdown: [
                'gemini' => ['seconds' => $geminiSeconds, 'model' => config('gemini.model')],
                'tts' => ['characters' => $charCount, 'model' => $modelId],
            ],
        );
    }
}
