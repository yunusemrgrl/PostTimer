<?php

use App\Models\Content;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/InstagramPostsDataMigrationTest.php';

it('dry-run reports the plan without writing anything', function () {
    [$teamId] = legacyTeamWithAccount();
    legacyPost($teamId);
    legacyPost($teamId, ['media_type' => 'REELS', 'media_product_type' => null, 'media_url' => mp4('a')]);

    $this->artisan('instagram:legacy-migrate')->assertSuccessful();

    expect(DB::table('contents')->count())->toBe(0)
        ->and(DB::table('publications')->count())->toBe(0)
        ->and(DB::table('instagram_posts')->count())->toBe(2);
});

it('refuses to run when contents or publications are not empty', function () {
    Content::factory()->create();

    $this->artisan('instagram:legacy-migrate', ['--force' => true])->assertFailed();

    expect(DB::table('contents')->count())->toBe(1);
});

it('succeeds with nothing to migrate when there are no legacy posts', function () {
    $this->artisan('instagram:legacy-migrate', ['--force' => true])->assertSuccessful();

    expect(DB::table('contents')->count())->toBe(0);
});

it('migrates everything under --force and is idempotent-guarded afterwards', function () {
    [$teamId] = legacyTeamWithAccount();
    $postId = legacyPost($teamId, [
        'status' => 'published',
        'published_at' => '2026-08-21 08:24:25',
    ]);
    DB::table('instagram_post_insights')->insert([
        ['instagram_post_id' => $postId, 'metric' => 'reach', 'period' => 'lifetime', 'value' => 10, 'fetched_at' => now()],
    ]);

    $this->artisan('instagram:legacy-migrate', ['--force' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Taşındı: 1 content, 1 publication');

    expect(DB::table('contents')->count())->toBe(1)
        ->and(DB::table('publications')->count())->toBe(1)
        ->and(Publication::query()->first()->media_id)->not->toBeNull()
        ->and(DB::table('instagram_post_insights')->whereNotNull('publication_id')->count())->toBe(1);

    // İkinci force: guard reddeder.
    $this->artisan('instagram:legacy-migrate', ['--force' => true])->assertFailed();
});

it('rolls back completely on unknown status and reports failure', function () {
    [$teamId] = legacyTeamWithAccount();
    legacyPost($teamId, ['status' => 'bogus_status']);

    $this->artisan('instagram:legacy-migrate', ['--force' => true])
        ->assertFailed()
        ->expectsOutputToContain('BAŞARISIZ');

    expect(DB::table('contents')->count())->toBe(0)
        ->and(DB::table('publications')->count())->toBe(0);
});
