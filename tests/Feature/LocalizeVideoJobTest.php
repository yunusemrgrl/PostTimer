<?php

use App\Domain\Video\Enums\LocalizationLanguage;
use App\Domain\Video\Enums\LocalizationStatus;
use App\Domain\Video\Services\GeminiVideoTranslationService;
use App\Jobs\LocalizeVideoJob;
use App\Models\Content;
use App\Models\Team;
use App\Models\TelegramSetting;
use App\Models\VideoLocalization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('marks the localization failed with the error message when the job permanently fails', function () {
    $content = Content::factory()->reels()->withoutProduct()->create();
    $localization = VideoLocalization::query()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => LocalizationStatus::Analyzing,
        'target_language' => LocalizationLanguage::Turkish,
    ]);

    (new LocalizeVideoJob($localization))->failed(new RuntimeException('Gemini yapılandırılmamış: GEMINI_API_KEY tanımlanmalı.'));

    expect($localization->fresh())
        ->status->toBe(LocalizationStatus::Failed)
        ->error_message->toBe('Gemini yapılandırılmamış: GEMINI_API_KEY tanımlanmalı.');
});

it('keeps the first error message when the localization is already failed', function () {
    $content = Content::factory()->reels()->withoutProduct()->create();
    $localization = VideoLocalization::query()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => LocalizationStatus::Failed,
        'target_language' => LocalizationLanguage::Turkish,
        'error_message' => 'ilk hata',
    ]);

    (new LocalizeVideoJob($localization))->failed(new RuntimeException('ikinci hata'));

    expect($localization->fresh())
        ->status->toBe(LocalizationStatus::Failed)
        ->error_message->toBe('ilk hata');
});

it('notifies the team on telegram when the localization permanently fails', function () {
    config(['services.telegram.bot_token' => 'test-token:abc']);

    $team = Team::factory()->create();
    TelegramSetting::factory()->for($team)->create();
    $content = Content::factory()->reels()->withoutProduct()->create(['team_id' => $team->id]);
    $localization = VideoLocalization::query()->create([
        'team_id' => $team->id,
        'content_id' => $content->id,
        'status' => LocalizationStatus::Analyzing,
        'target_language' => LocalizationLanguage::Turkish,
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
        '*' => Http::response(),
    ]);

    (new LocalizeVideoJob($localization))->failed(new RuntimeException('Gemini boş yanıt döndü'));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/bot')
            && str_contains($request['text'] ?? '', 'Yayın Başarısız')
            && str_contains($request['text'] ?? '', 'AI çeviri başarısız: Gemini boş yanıt döndü');
    });
});

it('does not throw when the team has no telegram configuration', function () {
    $content = Content::factory()->reels()->withoutProduct()->create();
    $localization = VideoLocalization::query()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => LocalizationStatus::Analyzing,
        'target_language' => LocalizationLanguage::Turkish,
    ]);

    Http::fake();

    (new LocalizeVideoJob($localization))->failed(new RuntimeException('boom'));

    Http::assertNothingSent();
    expect($localization->fresh()->status)->toBe(LocalizationStatus::Failed);
});

it('fails fast on missing gemini configuration without downloading the video', function () {
    config(['gemini.api_key' => '']);
    Http::fake();

    $service = new GeminiVideoTranslationService;

    try {
        $service->analyze('https://example.com/videos/test.mp4', 'tr');
        $this->fail('RuntimeException beklenirdi.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Gemini yapılandırılmamış: GEMINI_API_KEY tanımlanmalı.');
    }

    Http::assertNothingSent();
});
