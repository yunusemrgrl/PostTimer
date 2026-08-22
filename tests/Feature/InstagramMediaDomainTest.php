<?php

use App\Domain\Instagram\InstagramMediaFactory;
use App\Domain\Instagram\Media\CarouselChild;
use App\Domain\Instagram\Media\CarouselMedia;
use App\Domain\Instagram\Media\ImageMedia;
use App\Domain\Instagram\Media\ReelMedia;
use App\Domain\Instagram\Media\StoryMedia;
use App\Domain\Instagram\Media\VideoMedia;
use App\Models\InstagramPost;

function domainPost(array $attributes): InstagramPost
{
    return new InstagramPost(array_merge([
        'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_FEED,
        'caption' => 'Test caption',
        'media_url' => 'https://example.com/media/image.jpg',
        'story_link' => null,
        'alt_text' => null,
        'is_ai_generated' => false,
        'children' => [],
    ], $attributes));
}

it('maps every media_type + product_type combination to the right domain class', function () {
    $cases = [
        [InstagramPost::MEDIA_TYPE_IMAGE, InstagramPost::PRODUCT_TYPE_FEED, ImageMedia::class],
        [InstagramPost::MEDIA_TYPE_VIDEO, InstagramPost::PRODUCT_TYPE_FEED, VideoMedia::class],
        [InstagramPost::MEDIA_TYPE_VIDEO, InstagramPost::PRODUCT_TYPE_REELS, ReelMedia::class],
        [InstagramPost::MEDIA_TYPE_IMAGE, InstagramPost::PRODUCT_TYPE_STORY, StoryMedia::class],
        [InstagramPost::MEDIA_TYPE_VIDEO, InstagramPost::PRODUCT_TYPE_STORY, StoryMedia::class],
        [InstagramPost::MEDIA_TYPE_CAROUSEL_ALBUM, InstagramPost::PRODUCT_TYPE_FEED, CarouselMedia::class],
    ];

    foreach ($cases as [$mediaType, $productType, $expected]) {
        $post = domainPost(['media_type' => $mediaType, 'media_product_type' => $productType]);

        expect(InstagramMediaFactory::instance()->make($post))->toBeInstanceOf($expected);
    }
});

it('builds a normal image container payload with only image_url', function () {
    $media = InstagramMediaFactory::instance()->make(domainPost([]));

    $payload = $media->buildContainerPayload()->toPayload();

    expect($payload)
        ->toHaveKey('image_url', 'https://example.com/media/image.jpg')
        ->toHaveKey('media_type', 'IMAGE')
        ->toHaveKey('caption', 'Test caption')
        ->not->toHaveKey('video_url')
        ->not->toHaveKey('story_link')
        ->not->toHaveKey('is_carousel_item');
});

it('builds a feed video container payload with video_url', function () {
    $post = domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
        'media_url' => 'https://example.com/media/clip.mp4',
    ]);

    $payload = InstagramMediaFactory::instance()->make($post)->buildContainerPayload()->toPayload();

    expect($payload)
        ->toHaveKey('video_url', 'https://example.com/media/clip.mp4')
        ->toHaveKey('media_type', 'VIDEO')
        ->not->toHaveKey('image_url');
});

it('builds a reel container payload as media_type=REELS with video_url', function () {
    $post = domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_REELS,
        'media_url' => 'https://example.com/media/reel.mp4',
    ]);

    $payload = InstagramMediaFactory::instance()->make($post)->buildContainerPayload()->toPayload();

    expect($payload)
        ->toHaveKey('media_type', 'REELS')
        ->toHaveKey('video_url', 'https://example.com/media/reel.mp4')
        ->not->toHaveKey('image_url')
        ->not->toHaveKey('story_link');
});

it('builds a video story container payload as media_type=STORIES with video_url', function () {
    $post = domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_STORY,
        'media_url' => 'https://example.com/stories/story.mp4',
        'story_link' => 'https://example.com/links/go',
    ]);

    $payload = InstagramMediaFactory::instance()->make($post)->buildContainerPayload()->toPayload();

    expect($payload)
        ->toHaveKey('media_type', 'STORIES')
        ->toHaveKey('video_url', 'https://example.com/stories/story.mp4')
        ->toHaveKey('story_link', 'https://example.com/links/go')
        ->not->toHaveKey('image_url');
});
it('builds an image story container payload as media_type=STORIES with image_url', function () {
    $post = domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_STORY,
        'media_url' => 'https://example.com/stories/story.jpg',
    ]);

    $payload = (new InstagramMediaFactory)->make($post)->buildContainerPayload()->toPayload();

    expect($payload)
        ->toHaveKey('media_type', 'STORIES')
        ->toHaveKey('image_url', 'https://example.com/stories/story.jpg')
        ->not->toHaveKey('video_url');
});

it('builds a story video payload when media_type alone implies video', function () {
    $post = domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_STORIES,
        'media_url' => 'https://example.com/stories/reel.mp4',
    ]);

    $media = (new InstagramMediaFactory)->make($post);

    expect($media)->toBeInstanceOf(StoryMedia::class);

    $payload = $media->buildContainerPayload()->toPayload();

    expect($payload)
        ->toHaveKey('media_type', 'STORIES')
        ->toHaveKey('video_url', 'https://example.com/stories/reel.mp4')
        ->not->toHaveKey('image_url');
});

it('builds a carousel container payload with joined children ids', function () {
    $media = (new InstagramMediaFactory)->make(domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_CAROUSEL_ALBUM,
    ]));

    expect($media)->toBeInstanceOf(CarouselMedia::class);

    $payload = $media->buildContainerPayload(['ig_child_1', 'ig_child_2'])->toPayload();

    expect($payload)
        ->toHaveKey('media_type', 'CAROUSEL')
        ->toHaveKey('children', 'ig_child_1,ig_child_2')
        ->toHaveKey('caption', 'Test caption');
});

it('builds carousel item payloads for image and video children', function () {
    $image = CarouselChild::from(['url' => 'https://example.com/img.jpg']);
    $video = CarouselChild::from('https://example.com/video.mp4');

    expect($image->isVideo())->toBeFalse();
    expect($video->isVideo())->toBeTrue();

    $imagePayload = $image->containerPayload()->toPayload();
    expect($imagePayload)
        ->toHaveKey('image_url', 'https://example.com/img.jpg')
        ->toHaveKey('is_carousel_item', true)
        ->not->toHaveKey('video_url')
        // Karusel item'ları media_type göndermez (mevcut Meta davranışı).
        ->not->toHaveKey('media_type');

    $videoPayload = $video->containerPayload()->toPayload();
    expect($videoPayload)
        ->toHaveKey('video_url', 'https://example.com/video.mp4')
        ->toHaveKey('is_carousel_item', true)
        ->not->toHaveKey('image_url')
        ->not->toHaveKey('media_type');
});

it('throws when building a carousel payload without children ids', function () {
    (new InstagramMediaFactory)->make(domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_CAROUSEL_ALBUM,
    ]))->buildContainerPayload();
})->throws(RuntimeException::class);

it('ImageMedia returns the FEED insight metric list', function () {
    $media = (new InstagramMediaFactory)->make(domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_IMAGE,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_FEED,
    ]));

    expect($media->supportedInsightMetrics())
        ->toBe([
            'reach',
            'likes',
            'comments',
            'saved',
            'shares',
            'total_interactions',
            'views',
            'follows',
            'profile_visits',
            'profile_activity',
        ]);
});

it('VideoMedia returns the FEED insight metric list', function () {
    $media = (new InstagramMediaFactory)->make(domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_FEED,
        'media_url' => 'https://example.com/media/video.mp4',
    ]));

    expect($media->supportedInsightMetrics())
        ->toContain('reach', 'likes', 'comments', 'saved', 'views')
        ->not->toContain('impressions', 'ig_reels_avg_watch_time');
});

it('ReelMedia returns the REELS insight metric list', function () {
    $media = (new InstagramMediaFactory)->make(domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_REELS,
        'media_url' => 'https://example.com/media/reel.mp4',
    ]));

    expect($media->supportedInsightMetrics())
        ->toContain('reach', 'likes', 'comments', 'saved', 'shares', 'total_interactions', 'views', 'ig_reels_video_view_total_time', 'ig_reels_avg_watch_time')
        ->not->toContain('impressions', 'replies', 'navigation');
});

it('StoryMedia returns the STORY insight metric list without engagement metrics', function () {
    $media = (new InstagramMediaFactory)->make(domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_VIDEO,
        'media_product_type' => InstagramPost::PRODUCT_TYPE_STORY,
        'media_url' => 'https://example.com/media/story.mp4',
    ]));

    expect($media->supportedInsightMetrics())
        ->toContain('replies', 'navigation', 'follows', 'profile_visits', 'profile_activity', 'reach', 'views', 'shares', 'total_interactions')
        ->not->toContain('impressions', 'likes', 'comments', 'saved');
});

it('CarouselMedia does not support media insights', function () {
    $media = (new InstagramMediaFactory)->make(domainPost([
        'media_type' => InstagramPost::MEDIA_TYPE_CAROUSEL_ALBUM,
    ]));

    expect($media->supportedInsightMetrics())->toBe([]);
});
