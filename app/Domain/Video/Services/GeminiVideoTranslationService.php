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
     * ekrandaki yazilar, tespit edilen kaynak dil VE zaten hedef dilde
     * olup olmadigi tespiti — siki JSON semasi.
     *
     * Ceviri kurallari iletide gomulu: gorsel senkron (karede olan hareket/
     * urun), konusma temposu (segment suresine sigacak uzunluk) ve
     * tanitim tonu — kullanıcının manuel "videoya uygun cevir" talimatının
     * otomatiklesmis hali.
     */
    protected function buildPrompt(string $targetLanguage): string
    {
        return <<<PROMPT
You are a professional video localizer. Analyze the attached video and produce STRICT JSON (no markdown, no commentary) with exactly this shape:

{
  "source_language": "<ISO 639-1 code of the detected spoken language>",
  "detection": {
    "has_burned_in_subtitles": <true if subtitles/typeset text are already burned into the video frames, else false>,
    "burned_in_subtitle_language": "<ISO 639-1 code of the burned-in subtitle language, or null>",
    "already_in_target_language": <true if the viewer can already follow the video in {$targetLanguage} without any further work>,
    "reason": "<one short sentence explaining the already_in_target_language decision>"
  },
  "segments": [
    {"start": <number, seconds>, "end": <number, seconds>, "translation": "<utterance translated into {$targetLanguage}>"}
  ],
  "on_screen_text": ["<text visible on screen, kept in original language>"],
  "overlays": [
    {"start": <seconds when the text appears, or null if visible from the beginning>,
     "end": <seconds when it disappears, or null if visible until the end>,
     "style": {
       "fontFamily": "<font-family, e.g. 'Inter, sans-serif' or 'Georgia, serif'>",
       "fontWeight": "<300|400|500|600|700|800|900>",
       "fontSize": <px, number>,
       "fontStyle": "<normal|italic>",
       "color": "<hex color, e.g. #FFFFFF>",
       "backgroundColor": "<hex color with alpha, e.g. rgba(0,0,0,0.85)>",
       "textAlign": "<left|center|right>",
       "padding": "<px, e.g. 12px>",
       "borderRadius": "<px, e.g. 6px>",
       "maxWidth": "<%, e.g. 90>",
       "textShadow": "<css text-shadow or 'none'>",
       "opacity": <0-1 number, for text with see-through backgrounds>
     }
     "text": "<the on-screen text as written>",
     "translation": "<the same text translated into {$targetLanguage}>"}
  ]
}

Rules:
- Set "already_in_target_language" to TRUE when the video already communicates in {$targetLanguage}: the speech itself is {$targetLanguage}, OR burned-in subtitles/typeset text shown on the frames are {$targetLanguage} (e.g. a foreign-language video that already carries {$targetLanguage} subtitles). It must be FALSE when the viewer would need a translation or dubbing to follow it in {$targetLanguage}.
- VISUAL SYNC: watch what is happening on screen at every moment (hands, products, text, scene changes). Each segment translation must match what the viewer is seeing at that timestamp — reference the product or action being shown when the speaker refers to it ("this", "here", "look at that" must map to what is actually on screen).
- SPEECH PACING: the translated line must be naturally speakable within its segment window (start→end) at a normal narration pace of roughly 2.5–3 words per second in {$targetLanguage}. Condense rather than overflow; never leave a translation that clearly cannot fit its time window.
- PROMOTIONAL TONE: if the video is an ad or product showcase, translate as a native {$targetLanguage} advertising script that sells the product — natural, punchy, persuasive — NOT a literal word-by-word translation. Keep the same meaning, calls to action and emojis inside burned text.
- Cover the ENTIRE spoken audio as ordered segments; do not merge different speakers or skip content.
- Translate the meaning naturally (not word-by-word) into {$targetLanguage}.
- Every text PERMANENTLY rendered on the video frame (speech bubbles, caption boxes, burned-in titles) MUST also appear in overlays: bbox is the text's background box as percentages of the frame measured from the top-left corner (left + width <= 100, top + height <= 100), padded slightly so it fully covers the text AND its background box/bubble.
- One overlay entry per distinct text; start/end describe when it is visible on screen.
- TIMESTAMP PRECISION: all "start"/"end" values MUST be precise fractional seconds with up to 2 decimals (e.g. 3.12, 6.47) measured from the actual moment the speech/text begins or ends in the video. NEVER round to whole seconds — rounding causes overlapping or premature cuts between consecutive items. Adjacent items must not overlap: the next item's start must be >= the previous item's end.
- FULL OPAQUE COVER: the original on-screen text CANNOT be erased from the video — your overlay box is the ONLY thing hiding it. Therefore "backgroundColor" MUST be fully opaque (alpha = 1, e.g. "rgba(255,255,255,1)" or a solid hex) and "opacity" MUST be 1. NEVER use transparent or see-through backgrounds, gradients with alpha, or opacity below 1 — the original text would show through. Choose an opaque background color that fits the video's aesthetic (e.g. solid brand color, white, black).
- on_screen_text lists those same texts without position, kept in the original language. Both may be empty; never invent text that is not visible.
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

        // Zaten hedef dilde tespiti: videoya gömülü altyazı hedef dildeyse
        // ya da konuşma doğrudan hedef dildeyse çeviri/seslendirme gereksizdir.
        $detection = is_array($decoded['detection'] ?? null) ? $decoded['detection'] : [];

        $alreadyInTargetLanguage = filter_var(
            $detection['already_in_target_language'] ?? false,
            FILTER_VALIDATE_BOOL,
        );

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

        $onScreenText = [];

        foreach ((array) ($decoded['on_screen_text'] ?? []) as $item) {
            $item = trim((string) $item);

            if ($item !== '') {
                $onScreenText[] = $item;
            }
        }

        $overlays = [];

        foreach ((array) ($decoded['overlays'] ?? []) as $overlay) {
            if (! is_array($overlay)) {
                continue;
            }

            $text = trim((string) ($overlay['text'] ?? ''));
            $translation = trim((string) ($overlay['translation'] ?? ''));

            if ($text === '' || $translation === '') {
                continue;
            }

            $style = is_array($overlay['style'] ?? null) ? $overlay['style'] : [];

            $overlays[] = [
                'start' => is_numeric($overlay['start'] ?? null) ? max(0.0, (float) ($overlay['start'])) : null,
                'end' => is_numeric($overlay['end'] ?? null) ? max(0.0, (float) ($overlay['end'])) : null,
                'style' => $style,
                'text' => $text,
                'translation' => $translation,
            ];
        }

        if ($segments === [] && ! $alreadyInTargetLanguage && $overlays === []) {
            // Konuşma yok ve yakılacak ekran yazısı da yok — anlamlı bir
            // çeviri çıktısı üretilememiş (ör. sadece müzik + Gemınin
            // metin okuyamadığı kareler). Bu durumda analiz başarısız sayılır.
            throw new RuntimeException('Gemini yanitinda cevrilmis segment yok.');
        }

        return [
            'source_language' => $sourceLanguage,
            'already_in_target_language' => $alreadyInTargetLanguage,
            'has_burned_in_subtitles' => (bool) filter_var(
                $detection['has_burned_in_subtitles'] ?? false,
                FILTER_VALIDATE_BOOL,
            ),
            'burned_in_subtitle_language' => filled($detection['burned_in_subtitle_language'] ?? null)
                ? strtolower(trim((string) $detection['burned_in_subtitle_language']))
                : null,
            'detection_reason' => filled($detection['reason'] ?? null)
                ? trim((string) $detection['reason'])
                : null,
            'segments' => $segments,
            'on_screen_text' => $onScreenText,
            'overlays' => $overlays,
        ];
    }

    /**
     * Gemini bbox degerini (0-100 yuzde) dogrular; sayisal degilse null.
     */
    protected function clampPercent(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        return min(100.0, max(0.0, (float) $value));
    }
}
