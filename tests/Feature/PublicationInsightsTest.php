<?php

use App\Domain\Instagram\Services\InstagramInsightsService;
use App\Events\PublicationPublished;
use App\Jobs\SyncPublicationInsights;
use App\Listeners\SyncPublicationInsightsListener;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\InstagramPostInsight;
use App\Models\Publication;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function publishedPublication(string $mediaId = '123456789012'): Publication
{
    $teamId = Team::factory()->create()->id;

    $content = Content::factory()->reels()->create([
        'team_id' => $teamId,
        'caption' => 'Varsayılan caption',
    ]);

    $account = InstagramAccount::factory()->create([
        'team_id' => $teamId,
        'ig_user_id' => '2915115069225431',
        'api_host' => 'graph.instagram.com',
        'access_token' => 'account-token',
        'username' => 'hesap1',
    ]);

    return Publication::factory()->published()->create([
        'team_id' => $teamId,
        'content_id' => $content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
        'media_id' => $mediaId,
    ]);
}

it('stores publication insights response with publication_id', function () {
    $publication = publishedPublication('17977151531907852');

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'data' => [
                ['name' => 'impressions', 'period' => 'lifetime', 'values' => [['value' => 1500]]],
                ['name' => 'reach', 'period' => 'lifetime', 'values' => [['value' => 800]]],
                ['name' => 'views', 'period' => 'lifetime', 'values' => [['value' => 1200]]],
            ],
        ], 200),
        '*' => Http::response(),
    ]);

    $saved = (new InstagramInsightsService)->syncPublication($publication);

    expect($saved)->toContain('impressions', 'reach', 'views');

    $insights = InstagramPostInsight::where('publication_id', $publication->id)->get();

    expect($insights)->toHaveCount(3)
        ->and($insights->every(fn ($insight) => $insight->publication_id === $publication->id))->toBeTrue();

    $impressions = InstagramPostInsight::where('publication_id', $publication->id)
        ->where('metric', 'impressions')
        ->first();

    expect($impressions)->not->toBeNull()
        ->and($impressions->value)->toBe(1500)
        ->and($impressions->period)->toBe('lifetime');
});

it('does not create duplicate snapshots on re-run (new snapshot, not overwrite)', function () {
    $publication = publishedPublication('17977151531907852');

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'data' => [['name' => 'impressions', 'period' => 'lifetime', 'values' => [['value' => 100]]]],
        ], 200),
        '*' => Http::response(),
    ]);

    $service = new InstagramInsightsService;

    $service->syncPublication($publication);
    expect(InstagramPostInsight::where('publication_id', $publication->id)->count())->toBe(1);

    $service->syncPublication($publication);
    expect(InstagramPostInsight::where('publication_id', $publication->id)->count())->toBe(2);

    expect(InstagramPostInsight::where('publication_id', $publication->id)->pluck('fetched_at'))->toHaveCount(2);
});

it('throws a controlled RuntimeException when insights permission is missing (403)', function () {
    $publication = publishedPublication('17977151531907852');

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'error' => ['message' => 'Application does not have permission', 'code' => 10],
        ], 403),
        '*' => Http::response(),
    ]);

    expect(fn () => (new InstagramInsightsService)->syncPublication($publication))
        ->toThrow(RuntimeException::class, 'instagram_business_manage_insights');

    expect(InstagramPostInsight::where('publication_id', $publication->id)->count())->toBe(0);
});

it('logs a warning and returns empty when a metric is rejected (400)', function () {
    Log::spy();

    $publication = publishedPublication('17977151531907852');

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852/insights*' => Http::response([
            'error' => ['message' => 'metric must be one of the following values', 'code' => 100],
        ], 400),
        '*' => Http::response(),
    ]);

    $saved = (new InstagramInsightsService)->syncPublication($publication);

    expect($saved)->toBe([]);
    expect(InstagramPostInsight::where('publication_id', $publication->id)->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => $msg === 'instagram.insights.publication_api_error');
});

it('skips carousel publications (insights not supported)', function () {
    $teamId = Team::factory()->create()->id;

    $content = Content::factory()->carousel()->create(['team_id' => $teamId]);
    $account = InstagramAccount::factory()->create([
        'team_id' => $teamId,
        'ig_user_id' => '2915115069225433',
        'access_token' => 'account-token',
    ]);

    $publication = Publication::factory()->published()->create([
        'team_id' => $teamId,
        'content_id' => $content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
        'media_id' => 'carousel_123',
    ]);

    Http::fake(['*' => Http::response()]);

    $saved = (new InstagramInsightsService)->syncPublication($publication);

    expect($saved)->toBe([]);
    Http::assertNothingSent();
    expect(InstagramPostInsight::where('publication_id', $publication->id)->count())->toBe(0);
});

it('job does not run when publication has no media_id', function () {
    $publication = publishedPublication();
    $publication->update(['media_id' => null]);

    Http::fake(['*' => Http::response()]);

    $job = new SyncPublicationInsights($publication->fresh());
    $job->handle(new InstagramInsightsService);

    Http::assertNothingSent();
    expect(InstagramPostInsight::where('publication_id', $publication->id)->count())->toBe(0);
});

it('listener dispatches the sync job when a media_id exists', function () {
    Queue::fake();

    $publication = publishedPublication('17977151531942');

    app(SyncPublicationInsightsListener::class)
        ->handle(new PublicationPublished($publication));

    Queue::assertPushed(SyncPublicationInsights::class, 1);
});

it('listener does not dispatch when publication has no media_id', function () {
    Queue::fake();

    $publication = publishedPublication();
    $publication->update(['media_id' => null]);

    app(SyncPublicationInsightsListener::class)
        ->handle(new PublicationPublished($publication->fresh()));

    Queue::assertNothingPushed();
});
