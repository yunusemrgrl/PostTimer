<?php

namespace App\Domain\Video\Services\Contracts;

/**
 * Text-to-Speech sağlayıcısı (Strategy kontratı).
 * ElevenLabs implementasyonu: ElevenLabsTtsService.
 */
interface TextToSpeechProvider
{
    /**
     * Metni varsayılan sesle seslendirir, binary MP3 döner.
     */
    public function synthesize(string $text): string;
}
