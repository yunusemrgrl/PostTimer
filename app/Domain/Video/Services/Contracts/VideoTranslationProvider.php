<?php

namespace App\Domain\Video\Services\Contracts;

/**
 * Video analizi + hedef dile çeviri sağlayıcısı (Strategy kontratı).
 * Gemini implementasyonu: GeminiVideoTranslationService.
 *
 * @phpstan-type OverlayBbox array{left: float, top: float, width: float, height: float}
 * @phpstan-type OverlayPayload array{
 *     start: float|null, end: float|null,
 *     bbox: OverlayBbox,
 *     text: string, translation: string
 * }
 * @phpstan-type TranslationPayload array{
 *     source_language: string,
 *     already_in_target_language: bool,
 *     has_burned_in_subtitles: bool,
 *     burned_in_subtitle_language: string|null,
 *     detection_reason: string|null,
 *     segments: array<int, array{start: float, end: float, translation: string}>,
 *     on_screen_text: array<int, string>,
 *     overlays: array<int, OverlayPayload>
 * }
 */
interface VideoTranslationProvider
{
    /**
     * Videodaki konuşmayı transkript eder, ekrandaki yazıları okur ve
     * hedef dile timestamp'li olarak çevirir.
     *
     * @return TranslationPayload
     */
    public function analyze(string $mediaUrl, string $targetLanguage): array;
}
