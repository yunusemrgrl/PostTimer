<?php

namespace App\Domain\Video\Services\Contracts;

/**
 * Video analizi + hedef dile çeviri sağlayıcısı (Strategy kontratı).
 * Gemini implementasyonu: GeminiVideoTranslationService.
 *
 * @phpstan-type TranslationPayload array{
 *     source_language: string,
 *     segments: array<int, array{start: float, end: float, translation: string}>,
 *     on_screen_text: array<int, string>
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
