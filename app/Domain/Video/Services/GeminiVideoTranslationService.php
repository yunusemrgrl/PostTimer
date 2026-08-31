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
        // Config hatasını indirmeden ÖNCE ver: GEMINI_API_KEY boşken videoyu
        // boşuna indirmeyelim; aksi halde indirme hatası (403 vb.) asıl
        // yapılandırma hatasını maskeleyebilir.
        $apiKey = $this->requireConfig('api_key', 'GEMINI_API_KEY');

        $videoBytes = $this->downloadVideo($mediaUrl);

        // Bellek butcesi: ham videoyu base64 sonrasi serbest birak. Guzzle'in
        // 'json' secenegi govdeyi ikinci tam kopya olarak json_encode
        // ettigi icin buyuk videolarda 128M worker'lar tasiyordu (fatal).
        // Base64 alfabesi JSON escape gerektirmedigi icin govdeyi tek
        // kopya halinde elle kurup ham body olarak gonderiyoruz.
        $inlineVideo = base64_encode($videoBytes);
        unset($videoBytes);

        $body = '{"contents":[{"parts":[{"inline_data":{"mime_type":"video/mp4","data":"'
            .$inlineVideo.'"}},{"text":'
            .json_encode($this->buildPrompt($targetLanguage), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            .'}]}],"generationConfig":{"responseMimeType":"application/json","temperature":0.2}}';
        unset($inlineVideo);

        $response = $this->client($this->timeout())
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->send('POST', $this->url('/v1beta/models/'.$this->model().':generateContent'), [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $body,
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
     * Icerik diske akitilir (sink) — bellekte stream+string cift kopya
     * birikmez; 128M worker'lar icin kritik.
     */
    protected function downloadVideo(string $mediaUrl): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'gemini-video');

        if ($tempFile === false) {
            throw new RuntimeException('Video icin gecici dosya olusturulamadi.');
        }

        try {
            $response = Http::timeout($this->timeout())
                ->sink($tempFile)
                ->get($mediaUrl);

            if (! $response->successful()) {
                throw new RuntimeException("Video indirilemedi (HTTP {$response->status()}): {$mediaUrl}");
            }

            $size = (int) filesize($tempFile);

            $this->ensureWithinInlineLimit($size, $mediaUrl);

            $contents = (string) file_get_contents($tempFile);
        } finally {
            @unlink($tempFile);
        }

        if ($contents === '') {
            throw new RuntimeException('Video indirme bos dondu.');
        }

        return $contents;
    }

    /**
     * Gemini inline_data 20MB siniri. Sinir asimini erken reddeder —
     * buyuk videolar icin base64/json kopyalari hic baslamadan.
     */
    protected function ensureWithinInlineLimit(int $bytes, string $mediaUrl): void
    {
        if ($bytes === 0) {
            throw new RuntimeException('Video indirme bos dondu.');
        }

        if ($bytes > self::MAX_VIDEO_BYTES) {
            throw new RuntimeException(
                'Video inline analiz limitini asiyo ('
                .round($bytes / 1024 / 1024, 1).'MB > '
                .round(self::MAX_VIDEO_BYTES / 1024 / 1024).'MB): '.$mediaUrl
            );
        }
    }

    protected function model(): string
    {
        return (string) $this->config('model', 'gemini-3.6-flash');
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
