<?php

use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Product;
use App\Models\Publication;
use App\Models\Team;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a content with product', function () {
    $content = Content::factory()->create();

    expect($content->product)->toBeInstanceOf(Product::class)
        ->and($content->type)->toBe(Content::TYPE_IMAGE)
        ->and($content->surface)->toBe(Content::SURFACE_FEED);
});

it('distributes one content to multiple accounts via publications', function () {
    $teamId = Team::factory()->create()->id;
    $product = Product::factory()->create(['team_id' => $teamId]);
    $content = Content::factory()->create([
        'team_id' => $teamId,
        'product_id' => $product->id,
    ]);

    $accounts = InstagramAccount::factory()->count(3)->create(['team_id' => $teamId]);

    foreach ($accounts as $account) {
        Publication::factory()->create([
            'team_id' => $teamId,
            'content_id' => $content->id,
            'instagram_account_id' => $account->id,
            'ig_user_id' => $account->ig_user_id,
        ]);
    }

    expect($content->publications()->count())->toBe(3)
        ->and($product->contents()->count())->toBe(1);
});

it('prevents duplicate publication for same content and account', function () {
    $publication = Publication::factory()->create();

    Publication::factory()->create([
        'team_id' => $publication->team_id,
        'content_id' => $publication->content_id,
        'instagram_account_id' => $publication->instagram_account_id,
        'ig_user_id' => $publication->ig_user_id,
    ]);
})->throws(QueryException::class);

it('resolves caption override over content caption', function () {
    $content = Content::factory()->create(['caption' => 'Varsayılan caption']);
    $publication = Publication::factory()->create([
        'content_id' => $content->id,
        'team_id' => $content->team_id,
    ]);

    expect($publication->effectiveCaption())->toBe('Varsayılan caption');

    $publication->update(['caption_override' => 'Hesaba özel']);

    expect($publication->effectiveCaption())->toBe('Hesaba özel');
});

it('cascades publication deletion when content is deleted', function () {
    $publication = Publication::factory()->create();
    $contentId = $publication->content_id;

    Content::whereKey($contentId)->delete();

    expect(Publication::find($publication->id))->toBeNull();
});

it('supports all publication statuses including cancelled', function () {
    expect(array_keys(Publication::statuses()))->toContain(
        Publication::STATUS_DRAFT,
        Publication::STATUS_SCHEDULED,
        Publication::STATUS_PUBLISHING,
        Publication::STATUS_PUBLISHED,
        Publication::STATUS_FAILED,
        Publication::STATUS_FLAGGED,
        Publication::STATUS_CANCELLED,
    );
});
