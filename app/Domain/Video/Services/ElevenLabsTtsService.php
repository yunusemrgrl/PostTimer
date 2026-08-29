<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\Services\Contracts\TextToSpeechProvider;
use App\Support\Http\AbstractExternalApiClient;
use RuntimeException;

/**
 * ElevenLabs Text-to-Speech saglayicisi. Sadece HTTP cagrisi —
 * dubbing/polling YOK; TTS senkron tek istektir.
 *
 * Ses sabittir (config: ELEVENLABS_VOICE_ID); UI'da ses sectirilmez.
 * Uzun metinler cumle sinirlarinda parcalanir ve MP3 frame'leri
 * birlestirilerek tek ses dosyasi uretilir. HTTP altyapisi (timeout/
 * retry/hata) AbstractExternalApiClient tarafindan saglanir.
 */
class ElevenLabsTtsService extends AbstractExternalApiClient implements TextToSpeechProvider
{
    /**
     * Tek istekte gonderilecek maksimum karakter (API limiti 10k, guvenli pay).
     */
    protected const MAX_CHUNK_LENGTH = 4000;

    protected function configKey(): string
    {
        return 'elevenlabs';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.elevenlabs.io';
    }

    /**
     * Metni varsayilan sesle seslendirir, binary MP3 doner.
     */
    public function synthesize(string $text): string
    {
        $chunks = $this->chunkText($text);
        $outputFormat = (string) $this->config('tts.output_format', 'mp3_22050_64');
        $voiceId = $this->requireConfig('tts.voice_id', 'ELEVENLABS_VOICE_ID');

        $audio = '';

        foreach ($chunks as $chunk) {
            $response = $this->client($this->timeout())
                ->withHeaders(['xi-api-key' => $this->requireConfig('api_key', 'ELEVENLABS_API_KEY')])
                ->post($this->url("/v1/text-to-speech/{$voiceId}?output_format={$outputFormat}"), [
                    'text' => $chunk,
                    'model_id' => (string) $this->config('tts.model_id', 'eleven_multilingual_v2'),
                ]);

            if (! $response->successful()) {
                throw $this->apiError('TTS', $response);
            }

            $audio .= $response->body();
        }

        if ($audio === '') {
            throw new RuntimeException('ElevenLabs TTS bos ses dondu.');
        }

        return $audio;
    }

    /**
     * Metni cumle sinirlarinda parcalar (MP3 frame'leri birlestirilebilir
     * oldugundan parcalarun sirali eklenmesi gecerli tek ses uretir).
     *
     * @return array<int, string>
     */
    protected function chunkText(string $text): array
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            throw new RuntimeException('Seslendirilecek metin bos.');
        }

        if (mb_strlen($trimmed) <= self::MAX_CHUNK_LENGTH) {
            return [$trimmed];
        }

        $sentences = preg_split('/(?<=[.!?…:;])\s+/u', $trimmed) ?: [$trimmed];

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            // Tek cumle bile limitten uzunsa sert bol.
            while (mb_strlen($sentence) > self::MAX_CHUNK_LENGTH) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }

                $chunks[] = mb_substr($sentence, 0, self::MAX_CHUNK_LENGTH);
                $sentence = mb_substr($sentence, self::MAX_CHUNK_LENGTH);
            }

            if ($current !== '' && mb_strlen($current.' '.$sentence) > self::MAX_CHUNK_LENGTH) {
                $chunks[] = $current;
                $current = $sentence;

                continue;
            }

            $current = $current === '' ? $sentence : $current.' '.$sentence;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
