<?php

use App\Domain\Video\Enums\LocalizationLanguage;
use App\Domain\Video\Enums\LocalizationStatus;
use App\Domain\Video\Services\Contracts\VideoTranslationProvider;
use App\Domain\Video\Services\GeminiVideoTranslationService;
use App\Domain\Video\Services\VideoLocalizationService;
use App\Models\Content;
use App\Models\VideoLocalization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('marks the localization skipped when gemini reports the video is already in the target language', function () {
    Http::fake();

    $this->app->instance(VideoTranslationProvider::class, new class implements VideoTranslationProvider
    {
        public function analyze(string $mediaUrl, string $targetLanguage): array
        {
            return [
                'source_language' => 'ar',
                'already_in_target_language' => true,
                'has_burned_in_subtitles' => true,
                'burned_in_subtitle_language' => 'tr',
                'detection_reason' => 'Turkish subtitles are burned into the frames.',
                'segments' => [],
                'on_screen_text' => ['Şüphesiz insan,'],
                'overlays' => [],
            ];
        }
    });

    $content = Content::factory()->reels()->withoutProduct()->create();
    $localization = VideoLocalization::query()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => LocalizationStatus::Pending,
        'target_language' => LocalizationLanguage::Turkish,
    ]);

    app(VideoLocalizationService::class)->analyze($localization);

    $fresh = $localization->fresh();

    expect($fresh->status)->toBe(LocalizationStatus::Skipped)
        ->and($fresh->isSkipped())->toBeTrue()
        ->and($fresh->script)->toBeNull()
        ->and($fresh->translation['has_burned_in_subtitles'])->toBeTrue()
        ->and($fresh->detectionReason())->toBe('Turkish subtitles are burned into the frames.');
});

it('embeds visual sync, pacing and promotional tone rules into the gemini prompt', function () {
    $service = new GeminiVideoTranslationService;

    $prompt = (new ReflectionMethod($service, 'buildPrompt'))->invoke($service, 'tr');

    expect($prompt)
        ->toContain('VISUAL SYNC')
        ->toContain('SPEECH PACING')
        ->toContain('PROMOTIONAL TONE')
        ->toContain('already_in_target_language')
        ->toContain('tr');
});

it('parses a detection-only gemini response without segments', function () {
    $service = new GeminiVideoTranslationService;

    $payload = (new ReflectionMethod($service, 'parseTranslation'))->invoke($service, <<<'JSON'
        {
            "source_language": "ar",
            "detection": {
                "has_burned_in_subtitles": true,
                "burned_in_subtitle_language": "tr",
                "already_in_target_language": true,
                "reason": "Burned-in Turkish subtitles."
            },
            "segments": [],
            "on_screen_text": ["إِنَّ الْإِنْسَانَ", "Şüphesiz insan,"],
            "overlays": []
        }
    JSON);

    expect($payload['already_in_target_language'])->toBeTrue()
        ->and($payload['has_burned_in_subtitles'])->toBeTrue()
        ->and($payload['burned_in_subtitle_language'])->toBe('tr')
        ->and($payload['detection_reason'])->toBe('Burned-in Turkish subtitles.')
        ->and($payload['segments'])->toBe([])
        ->and($payload['on_screen_text'])->toHaveCount(2);
});

it('accepts an overlay-only response for speechless (music-only) videos', function () {
    $service = new GeminiVideoTranslationService;

    $payload = (new ReflectionMethod($service, 'parseTranslation'))->invoke($service, <<<'JSON'
        {
            "source_language": "en",
            "detection": {
                "has_burned_in_subtitles": true,
                "burned_in_subtitle_language": "en",
                "already_in_target_language": false,
                "reason": "English burned-in captions over background music."
            },
            "segments": [],
            "on_screen_text": ["The best Christmas gift for book lovers in 2026"],
            "overlays": [
                {"start": 0, "end": 5, "bbox": {"left": 8, "top": 14, "width": 84, "height": 14},
                 "text": "The best Christmas gift for book lovers in 2026",
                 "translation": "2026'nın kitapseverler için en iyi Noel hediyesi"}
            ]
        }
    JSON);

    expect($payload['segments'])->toBe([])
        ->and($payload['overlays'])->toHaveCount(1)
        ->and($payload['overlays'][0]['translation'])->toBe("2026'nın kitapseverler için en iyi Noel hediyesi");
});

it('marks overlay-only videos analyzed with a null script so no tts is triggered', function () {
    Http::fake();

    $this->app->instance(VideoTranslationProvider::class, new class implements VideoTranslationProvider
    {
        public function analyze(string $mediaUrl, string $targetLanguage): array
        {
            return [
                'source_language' => 'en',
                'already_in_target_language' => false,
                'has_burned_in_subtitles' => true,
                'burned_in_subtitle_language' => 'en',
                'detection_reason' => 'English captions over music.',
                'segments' => [],
                'on_screen_text' => ["Comment 'book' if u Want one"],
                'overlays' => [
                    [
                        'start' => 0.0,
                        'end' => 15.0,
                        'bbox' => ['left' => 10.0, 'top' => 12.0, 'width' => 80.0, 'height' => 12.0],
                        'text' => "Comment 'book' if u Want one",
                        'translation' => "İstiyorsan 'kitap' yaz",
                    ],
                ],
            ];
        }
    });

    $content = Content::factory()->reels()->withoutProduct()->create();
    $localization = VideoLocalization::query()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => LocalizationStatus::Pending,
        'target_language' => LocalizationLanguage::Turkish,
    ]);

    app(VideoLocalizationService::class)->analyze($localization);

    $fresh = $localization->fresh();

    expect($fresh->status)->toBe(LocalizationStatus::Analyzed)
        ->and($fresh->script)->toBeNull()
        ->and($fresh->translation['overlays'])->toHaveCount(1);
});
