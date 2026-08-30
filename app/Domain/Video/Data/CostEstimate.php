<?php

namespace App\Domain\Video\Data;

/**
 * AI yerelleştirme maliyeti için immutable value object (Beyond CRUD DTO).
 *
 * Gemini (video analizi, video saniyesi başına) ve ElevenLabs (TTS, karakter
 * başına) maliyetlerini ayrı ayrı tutar; toplam ve döküm (breakdown) ile
 * birlikte persist edilir.
 *
 * @immutable
 */
final class CostEstimate
{
    public function __construct(
        public readonly float $geminiCost,
        public readonly float $ttsCost,
        public readonly string $currency = 'USD',
        public readonly array $breakdown = [],
    ) {}

    public function total(): float
    {
        return round($this->geminiCost + $this->ttsCost, 4);
    }

    /**
     * Persist için array temsili (cost_breakdown kolonu).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'gemini' => round($this->geminiCost, 4),
            'tts' => round($this->ttsCost, 4),
            'currency' => $this->currency,
            'detail' => $this->breakdown,
        ];
    }
}
