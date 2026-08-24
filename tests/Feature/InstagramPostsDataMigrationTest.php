<?php

use App\Models\Publication;
use App\Support\InstagramPostsDataMigrator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function legacyTeamWithAccount(): array
{
    $teamId = DB::table('teams')->insertGetId([
        'name' => 'Test Team',
        'slug' => 'test-team',
        'owner_id' => DB::table('users')->insertGetId(['name' => 'u', 'email' => 'u@test.local', 'password' => 'x']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $accountId = DB::table('instagram_accounts')->insertGetId([
        'team_id' => $teamId,
        'ig_user_id' => '29151114341155408',
        'access_token' => 'token',
        'api_host' => 'graph.instagram.com',
        'username' => 'yunusemregurlu',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$teamId, $accountId];
}

function legacyPost(int $teamId, array $overrides = []): int
{
    return DB::table('instagram_posts')->insertGetId([
        'team_id' => $teamId,
        'ig_user_id' => '29151114341155408',
        'media_type' => 'VIDEO',
        'media_product_type' => 'REELS',
        'caption' => 'test',
        'media_url' => 'https://r2.example.com/media/video.mp4',
        'is_ai_generated' => false,
        'container_id' => 'ctr_'.$fake = uniqid(),
        'media_id' => 'med_'.uniqid(),
        'status' => 'published',
        'published_at' => '2026-08-21 08:24:25',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

function mp4(string $name): string
{
    return "https://r2.example.com/media/{$name}.mp4";
}

it('migrates the production-like legacy dataset end to end', function () {
    [$teamId, $accountId] = legacyTeamWithAccount();

    // Prod dağılımının aynısı: 2 güncel VIDEO+REELS, 4 legacy REELS, 2 legacy STORIES
    $ids = [];
    $ids[] = legacyPost($teamId); // VIDEO+REELS
    $ids[] = legacyPost($teamId, ['caption' => 'yeni test yorum', 'first_comment' => 'yeni test yorum']);
    $ids[] = legacyPost($teamId, ['media_type' => 'REELS', 'media_product_type' => null, 'caption' => '', 'media_url' => mp4('a')]);
    $ids[] = legacyPost($teamId, ['media_type' => 'REELS', 'media_product_type' => null, 'media_url' => mp4('a'), 'is_ai_generated' => true]);
    $ids[] = legacyPost($teamId, ['media_type' => 'STORIES', 'media_product_type' => null, 'media_url' => mp4('b'), 'is_ai_generated' => true]);
    $ids[] = legacyPost($teamId, ['media_type' => 'REELS', 'media_product_type' => null, 'media_url' => mp4('c'), 'like_count' => 0, 'comments_count' => 0]);
    $ids[] = legacyPost($teamId, ['media_type' => 'REELS', 'media_product_type' => null, 'media_url' => mp4('d'), 'like_count' => 0, 'comments_count' => 0]);
    $ids[] = legacyPost($teamId, ['media_type' => 'STORIES', 'media_product_type' => null, 'media_url' => mp4('d')]);

    foreach ([$ids[5], $ids[6], $ids[7]] as $postId) {
        DB::table('instagram_post_insights')->insert([
            ['instagram_post_id' => $postId, 'metric' => 'reach', 'period' => 'lifetime', 'value' => 10, 'fetched_at' => now()],
            ['instagram_post_id' => $postId, 'metric' => 'likes', 'period' => 'lifetime', 'value' => 0, 'fetched_at' => now()],
            ['instagram_post_id' => $postId, 'metric' => 'comments', 'period' => 'lifetime', 'value' => 0, 'fetched_at' => now()],
        ]);
    }

    [$contentCount, $publicationCount] = InstagramPostsDataMigrator::migratePostsToContentsAndPublications();
    $backfilled = InstagramPostsDataMigrator::backfillInsightPublicationIds();

    expect($contentCount)->toBe(8)
        ->and($publicationCount)->toBe(8)
        ->and($backfilled)->toBe(9)
        // Eski veri yerinde duruyor
        ->and(DB::table('instagram_posts')->count())->toBe(8)
        ->and(DB::table('instagram_post_insights')->count())->toBe(9)
        ->and(DB::table('instagram_post_insights')->whereNull('publication_id')->count())->toBe(0)
        ->and(DB::table('instagram_post_insights')->whereNotNull('publication_id')->count())->toBe(9)
        // Duplicate yok
        ->and(DB::table('publications')->select('media_id')->groupBy('media_id')->havingRaw('count(*) > 1')->count())->toBe(0);

    // Her post kendi Content+Publication'ına bağlı; ID varsayımı yapılmadan
    // media_id üzerinden doğrulanır.
    $expected = [
        ['VIDEO', 'REELS'], ['VIDEO', 'REELS'], // güncel VIDEO+REELS
        ['VIDEO', 'REELS'], ['VIDEO', 'REELS'], // legacy REELS (.mp4)
        ['VIDEO', 'STORY'],                     // legacy STORIES (.mp4)
        ['VIDEO', 'REELS'], ['VIDEO', 'REELS'], // legacy REELS (.mp4)
        ['VIDEO', 'STORY'],                     // legacy STORIES (.mp4)
    ];

    foreach ($ids as $index => $oldPostId) {
        $post = DB::table('instagram_posts')->where('id', $oldPostId)->first();
        $publication = DB::table('publications')->where('media_id', $post->media_id)->first();
        $content = DB::table('contents')->where('id', $publication->content_id)->first();

        expect($publication)->not->toBeNull()
            ->and($publication->status)->toBe(Publication::STATUS_PUBLISHED)
            ->and($publication->ig_user_id)->toBe('29151114341155408')
            ->and($publication->instagram_account_id)->toBe($accountId)
            ->and($publication->container_id)->toBe($post->container_id)
            ->and($publication->permalink)->toBe($post->permalink)
            ->and($content->type)->toBe($expected[$index][0])
            ->and($content->surface)->toBe($expected[$index][1])
            ->and($content->media_url)->toBe($post->media_url)
            ->and($content->team_id)->toBe($teamId)
            ->and($content->product_id)->toBeNull();
    }

    // Boş string caption aynen korunuyor (post 3)
    $emptyCaptionContent = DB::table('contents')->where('caption', '')->count();
    expect($emptyCaptionContent)->toBe(1);

    // Aynı media_url'li iki post (index 2 ve 3) AYRI Content'lerde
    $sharedUrlContents = DB::table('contents')->where('media_url', mp4('a'))->count();
    expect($sharedUrlContents)->toBe(2);

    // publication_id kolonu nullable: publication'sız insight eklenebilir
    $nullableOk = DB::table('instagram_post_insights')->insert([
        'instagram_post_id' => $ids[0], 'metric' => 'views', 'period' => 'lifetime',
        'value' => 1, 'fetched_at' => now(),
    ]);
    expect($nullableOk)->toBeTrue()
        ->and(Schema::hasColumn('instagram_post_insights', 'publication_id'))->toBeTrue();
});

it('throws and rolls back completely on unknown status', function () {
    [$teamId] = legacyTeamWithAccount();
    legacyPost($teamId, ['status' => 'weird_status']);

    expect(fn () => InstagramPostsDataMigrator::migratePostsToContentsAndPublications())
        ->toThrow(RuntimeException::class);

    expect(DB::table('contents')->count())->toBe(0)
        ->and(DB::table('publications')->count())->toBe(0)
        // Eski veri hâlâ duruyor
        ->and(DB::table('instagram_posts')->count())->toBe(1);
});

it('throws when no instagram account matches team and ig_user_id', function () {
    [$teamId] = legacyTeamWithAccount();
    legacyPost($teamId, ['ig_user_id' => '99999999999999']);

    expect(fn () => InstagramPostsDataMigrator::migratePostsToContentsAndPublications())
        ->toThrow(RuntimeException::class);

    expect(DB::table('contents')->count())->toBe(0);
});

it('throws on unknown media type combination', function () {
    [$teamId] = legacyTeamWithAccount();
    legacyPost($teamId, ['media_type' => 'CAROUSEL', 'media_product_type' => 'STORY']);

    expect(fn () => InstagramPostsDataMigrator::migratePostsToContentsAndPublications())
        ->toThrow(RuntimeException::class);

    expect(DB::table('contents')->count())->toBe(0);
});

it('refuses to run when contents already exist', function () {
    [$teamId] = legacyTeamWithAccount();
    legacyPost($teamId);

    DB::table('contents')->insert([
        'team_id' => $teamId, 'type' => 'IMAGE', 'surface' => 'FEED',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => InstagramPostsDataMigrator::migratePostsToContentsAndPublications())
        ->toThrow(RuntimeException::class);
});

it('prevents orphan insights at the database level (FK cascade)', function () {
    [$teamId] = legacyTeamWithAccount();
    legacyPost($teamId);

    InstagramPostsDataMigrator::migratePostsToContentsAndPublications();

    // instagram_post_id FK'sı bu aşamada bilinçli olarak korunur:
    // var olmayan bir posta insight eklenemez (orphan veri DB seviyesinde imkânsız).
    expect(fn () => DB::table('instagram_post_insights')->insert([
        'instagram_post_id' => 999999, 'metric' => 'reach', 'period' => 'lifetime',
        'value' => 1, 'fetched_at' => now(),
    ]))->toThrow(QueryException::class);
});
