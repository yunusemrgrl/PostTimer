<?php

use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Services\InstagramPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function connectAccountForDomain(InstagramPost $post): InstagramAccount
{
    return InstagramAccount::factory()
        ->for($post->team)
        ->withToken('account-token')
        ->create([
            'ig_user_id' => $post->ig_user_id,
            'api_host' => 'graph.instagram.com',
        ]);
}

it('syncs like_count, comments_count, permalink, thumbnail_url from API media response', function () {
    $apiResponse = [
        'id' => '17977151531907852',
        'caption' => 'test caption',
        'media_type' => 'VIDEO',
        'media_product_type' => 'REELS',
        'media_url' => 'https://instagram.fbcdn.net/video.mp4',
        'thumbnail_url' => 'https://instagram.fbcdn.net/thumb.jpg',
        'permalink' => 'https://www.instagram.com/reel/DcS5mG7AOic/',
        'timestamp' => '2026-08-21T08:24:19+0000',
        'like_count' => 10,
        'comments_count' => 1,
    ];

    $post = InstagramPost::factory()->published()->create([
        'media_id' => '17977151531907852',
        'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_REELS,
    ]);

    connectAccountForDomain($post);

    Http::fake([
        'https://graph.instagram.com/*/17977151531907852*' => Http::response($apiResponse, 200),
        '*' => Http::response(),
    ]);

    $account = InstagramAccount::query()->where('ig_user_id', $post->ig_user_id)->first();
    $client = InstagramPublishingService::forAccount($account);

    $data = $client->getMedia($post->media_id);

    $post->update([
        'like_count' => $data['like_count'],
        'comments_count' => $data['comments_count'],
        'permalink' => $data['permalink'],
        'thumbnail_url' => $data['thumbnail_url'],
        'ig_media_timestamp' => $data['timestamp'],
    ]);

    expect($post->fresh())
        ->like_count->toBe(10)
        ->comments_count->toBe(1)
        ->permalink->toBe('https://www.instagram.com/reel/DcS5mG7AOic/')
        ->thumbnail_url->toBe('https://instagram.fbcdn.net/thumb.jpg');
});

it('supportedInsightMetrics returns correct metrics for each product type', function () {
    $feed = InstagramPost::factory()->create([
        'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_FEED,
    ]);
    expect($feed->supportedInsightMetrics())
        ->toContain('reach', 'likes', 'comments', 'saved', 'shares', 'total_interactions', 'views')
        // impressions, 2 Temmuz 2024 sonrası medya için deprecated (Meta v25.0)
        ->not->toContain('impressions', 'ig_reels_video_view_total_time');

    $reels = InstagramPost::factory()->reels()->create();
    expect($reels->supportedInsightMetrics())
        ->toContain('views', 'ig_reels_video_view_total_time', 'ig_reels_avg_watch_time')
        ->not->toContain('impressions');

    $story = InstagramPost::factory()->story()->create();
    expect($story->supportedInsightMetrics())
        ->toContain('replies', 'navigation', 'follows', 'profile_visits', 'profile_activity')
        ->not->toContain('impressions', 'likes', 'comments', 'saved');

    $carousel = InstagramPost::factory()->carousel()->create();
    expect($carousel->supportedInsightMetrics())->toBe([]);
});

it('supportedInsightMetrics falls back to story metrics when media_product_type is null and media_type is STORIES', function () {
    $post = InstagramPost::factory()->published()->create([
        'media_type' => InstagramPost::MEDIA_TYPE_STORIES,
        'media_product_type' => null,
        'media_id' => 'story_media_1',
    ]);

    expect($post->supportedInsightMetrics())
        ->toContain('replies', 'navigation', 'follows', 'profile_visits', 'profile_activity')
        ->not->toContain('impressions', 'likes', 'comments', 'saved');
});

it('supportedInsightMetrics falls back to feed metrics for image posts without media_product_type', function () {
    $post = InstagramPost::factory()->published()->create([
        'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
        'media_product_type' => null,
    ]);

    expect($post->supportedInsightMetrics())
        ->toContain('reach', 'likes', 'comments', 'saved')
        ->not->toContain('impressions');
});
