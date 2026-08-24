<?php

use App\Domain\Instagram\HasPublishableMedia;
use App\Domain\Instagram\InstagramMediaFactory;
use App\Domain\Instagram\Media\CarouselMedia;
use App\Domain\Instagram\Media\ImageMedia;
use App\Domain\Instagram\Media\ReelMedia;
use App\Domain\Instagram\Media\StoryMedia;
use App\Models\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('implements the publishable media contract', function () {
    $content = Content::factory()->create();

    expect($content)->toBeInstanceOf(HasPublishableMedia::class);
});

it('maps a feed image content to ImageMedia', function () {
    $content = Content::factory()->create();

    expect(InstagramMediaFactory::instance()->make($content))->toBeInstanceOf(ImageMedia::class);
});

it('maps a reels content to ReelMedia', function () {
    $content = Content::factory()->reels()->create();

    expect(InstagramMediaFactory::instance()->make($content))->toBeInstanceOf(ReelMedia::class);
});

it('maps a story content to StoryMedia', function () {
    $content = Content::factory()->story()->create();

    expect(InstagramMediaFactory::instance()->make($content))->toBeInstanceOf(StoryMedia::class);
});

it('maps a carousel content to CarouselMedia', function () {
    $content = Content::factory()->carousel()->create();
    $media = InstagramMediaFactory::instance()->make($content);

    expect($media)->toBeInstanceOf(CarouselMedia::class)
        ->and(count($media->childUrls()))->toBe(2)
        ->and($media->post())->toBe($content);
});
