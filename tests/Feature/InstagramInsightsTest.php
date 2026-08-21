<?php

use App\Jobs\SyncInstagramPostInsights;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\InstagramPostInsight;
use App\Services\InstagramInsightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function connectAccountForInsights(InstagramPost $post): InstagramAccount
{
    return InstagramAccount::factory()
        ->for($post->team)
        ->withToken('account-token')
        ->create([
            'ig_user_id' => $post->ig_user_id,
            'api_host' => 'graph.instagram.com',
        ]);
}

function publishedReelsPost(): InstagramPost
{
    return InstagramPost::factory()->reels()->published()->create([
        'media_id' => '17977151531907852',
        'media_product_type' => InstagramPost::PRODUCT_TYPE_REELS,
    ]);
}

it('parses insights response and stores snapshots in DB', function () {
    $post = publishedReelsPost();
    connectAccountForInsights($post);

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'data' => [
                [
                    'name' => 'impressions',
                    'period' => 'lifetime',
                    'values' => [['value' => 1500]],
                ],
                [
                    'name' => 'reach',
                    'period' => 'lifetime',
                    'values' => [['value' => 800]],
                ],
                [
                    'name' => 'views',
                    'period' => 'lifetime',
                    'values' => [['value' => 1200]],
                ],
            ],
        ], 200),
        '*' => Http::response(),
    ]);

    $service = new InstagramInsightsService;
    $saved = $service->syncPostInsights($post);

    expect($saved)->toContain('impressions', 'reach', 'views');

    expect(InstagramPostInsight::where('instagram_post_id', $post->id)->count())->toBe(3);

    $impressions = InstagramPostInsight::where('instagram_post_id', $post->id)
        ->where('metric', 'impressions')
        ->first();

    expect($impressions)->not->toBeNull()
        ->and($impressions->value)->toBe(1500)
        ->and($impressions->period)->toBe('lifetime');
});

it('does not create duplicate snapshots when sync runs again (new snapshot, not duplicate)', function () {
    $post = publishedReelsPost();
    connectAccountForInsights($post);

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'data' => [
                ['name' => 'impressions', 'period' => 'lifetime', 'values' => [['value' => 100]]],
            ],
        ], 200),
        '*' => Http::response(),
    ]);

    $service = new InstagramInsightsService;

    // İlk sync
    $service->syncPostInsights($post);
    expect(InstagramPostInsight::where('instagram_post_id', $post->id)->count())->toBe(1);

    // İkinci sync — yeni snapshot (trend için), duplicate değil
    $service->syncPostInsights($post);
    expect(InstagramPostInsight::where('instagram_post_id', $post->id)->count())->toBe(2);

    // Her snapshot farklı fetched_at'e sahip
    $timestamps = InstagramPostInsight::where('instagram_post_id', $post->id)->pluck('fetched_at');
    expect($timestamps)->toHaveCount(2);
});

it('throws controlled RuntimeException when insights permission is missing (403)', function () {
    $post = publishedReelsPost();
    connectAccountForInsights($post);

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'error' => ['message' => 'Application does not have permission', 'code' => 10],
        ], 403),
        '*' => Http::response(),
    ]);

    $service = new InstagramInsightsService;

    expect(fn () => $service->syncPostInsights($post))
        ->toThrow(RuntimeException::class, 'instagram_business_manage_insights');

    // Hiç snapshot yazılmamış olmalı
    expect(InstagramPostInsight::where('instagram_post_id', $post->id)->count())->toBe(0);
});

it('logs warning and continues when a metric is rejected by API (400)', function () {
    Log::spy();

    $post = publishedReelsPost();
    connectAccountForInsights($post);

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'error' => ['message' => 'metric[5] must be one of the following values: ...', 'code' => 100],
        ], 400),
        '*' => Http::response(),
    ]);

    $service = new InstagramInsightsService;
    $saved = $service->syncPostInsights($post);

    // 400 hatada job fail olmaz; boş dizi döner.
    expect($saved)->toBe([]);
    expect(InstagramPostInsight::where('instagram_post_id', $post->id)->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === 'instagram.insights.api_error');
});

it('skips carousel posts (insights not supported)', function () {
    $post = InstagramPost::factory()->carousel()->published()->create([
        'media_id' => 'carousel_123',
    ]);
    connectAccountForInsights($post);

    Http::fake(['*' => Http::response()]);

    $service = new InstagramInsightsService;
    $saved = $service->syncPostInsights($post);

    expect($saved)->toBe([]);
    Http::assertNothingSent();
});

it('job does not run when post has no media_id', function () {
    $post = InstagramPost::factory()->reels()->create([
        'status' => InstagramPost::STATUS_DRAFT,
        'media_id' => null,
    ]);
    connectAccountForInsights($post);

    Http::fake(['*' => Http::response()]);

    // Job dispatch edildiğinde media_id null ise handle() erken döner.
    $job = new SyncInstagramPostInsights($post);
    $job->handle(new InstagramInsightsService);

    Http::assertNothingSent();
    expect(InstagramPostInsight::count())->toBe(0);
});
