<?php

namespace App\Domain\Video\Services;

use App\Domain\Video\Services\Contracts\VideoTranslationProvider;
use App\Support\Http\AbstractExternalApiClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Gemini video analizi + hedef dile ceviri saglayicisi.
 *
 *   Video URL -> indir -> inline base64 -> generateContent (JSON mode)
 *   -> {source_language, segments[{start,end,translation}], on_screen_text[]}
 *
 * Render YOK; HTTP altyapisi (timeout/retry/hata) AbstractExternalApiClient
 * tarafindan saglanir (Template Method).
 *
 * @phpstan-import-type TranslationPayload from \App\Domain\Video\Services\Contracts\VideoTranslationProvider
 */
class GeminiVideoTranslationService extends AbstractExternalApiClient implements VideoTranslationProvider
{
    /**
     * Gemini inline data limiti 20MB — uzerine dusen video reddedilir.
     */
    protected const MAX_VIDEO_BYTES = 20 * 1024 * 1024;

    protected function configKey(): string
    {
        return 'gemini';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com';
    }

    /**
     * Videodaki konusmayi transkript eder, ekrandaki yazilari okur ve
     * hedef dile timestamp'li olarak cevirir.
     *
     * @return TranslationPayload
     */
    public function analyze(string $mediaUrl, string $targetLanguage): array
    {
        $videoBytes = $this->downloadVideo($mediaUrl);

        $response = $this->client($this->timeout())
            ->withHeaders(['x-goog-api-key' => $this->requireConfig('api_key', 'GEMINI_API_KEY')])
            ->post($this->url('/v1beta/models/'.$this->model().':generateContent'), [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'inline_data' => [
                                    'mime_type' => 'video/mp4',
                                    'data' => base64_encode($videoBytes),
                                ],
                            ],
                            ['text' => $this->buildPrompt($targetLanguage)],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.2,
                ],
            ]);

        if (! $response->successful()) {
            throw $this->apiError('video analizi', $response);
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Gemini bos yanit dondu (video analiz edilemedi).');
        }

        return $this->parseTranslation($text);
    }

    /**
     * Videoyu R2'den indirir. Public URL kullanildigindan ek imza gerekmez.
     */
    protected function downloadVideo(string $mediaUrl): string
    {
        $response = Http::timeout($this->timeout())->get($mediaUrl);

        if (! $response->successful()) {
            throw new RuntimeException("Video indirilemedi (HTTP {$response->status()}): {$mediaUrl}");
        }

        $bytes = $response->body();

        if ($bytes === '') {
            throw new RuntimeException('Video indirme bos dondu.');
        }

        if (strlen($bytes) > self::MAX_VIDEO_BYTES) {
            throw new RuntimeException(
                'Video inline analiz limitini asiyor ('.round(strlen($bytes) / 1024 / 1024, 1).'MB > 20MB).'
            );
        }

        return $bytes;
    }

    protected function model(): string
    {
        return (string) $this->config('model', 'gemini-2.5-flash');
    }

    /**
     * Gemini'ye gonderilen talimat: timestamp'li segment cevirileri,
     * ekrandaki yazilar ve tespit edilen kaynak dil — siki JSON semasi.
     */
    protected function buildPrompt(string $targetLanguage): string
    {
        return <<<PROMPT
You are a professional video localizer. Analyze the attached video and produce STRICT JSON (no markdown, no commentary) with exactly this shape:

{
  "source_language": "<ISO 639-1 code of the detected spoken language>",
  "segments": [
    {"start": <number, seconds>, "end": <number, seconds>, "translation": "<utterance translated into {$targetLanguage}>"}
  ],
  "on_screen_text": ["<text visible on screen, kept in original language>"]
}

Rules:
- Cover the ENTIRE spoken audio as ordered segments; do not merge different speakers or skip content.
- Translate the meaning naturally (not word-by-word) into {$targetLanguage}.
- on_screen_text may be empty; never invent text that is not visible.
- Output only the JSON object.
PROMPT;
    }

    /**
     * Gemini JSON yanitini dogrular ve normalize eder. LLM ciktisi
     * oldugundan eksik/bozuk alanlara karsi savunmaci davranir.
     *
     * @return TranslationPayload
     */
    protected function parseTranslation(string $text): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Gemini yaniti JSON olarak ayristirilamadi: '.$exception->getMessage());
        }

        $sourceLanguage = trim((string) ($decoded['source_language'] ?? ''));

        if ($sourceLanguage === '') {
            throw new RuntimeException('Gemini yanitinda source_language eksik.');
        }

        $segments = [];

        foreach ((array) ($decoded['segments'] ?? []) as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $translation = trim((string) ($segment['translation'] ?? ''));

            if ($translation === '') {
                continue;
            }

            $segments[] = [
                'start' => (float) ($segment['start'] ?? 0),
                'end' => (float) ($segment['end'] ?? 0),
                'translation' => $translation,
            ];
        }

        if ($segments === []) {
            throw new RuntimeException('Gemini yanitinda cevrilmis segment yok.');
        }

        $onScreenText = [];

        foreach ((array) ($decoded['on_screen_text'] ?? []) as $item) {
            $item = trim((string) $item);

            if ($item !== '') {
                $onScreenText[] = $item;
            }
        }

        return [
            'source_language' => $sourceLanguage,
            'segments' => $segments,
            'on_screen_text' => $onScreenText,
        ];
    }
}
