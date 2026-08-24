<?php

namespace App\Support;

use App\Models\Publication;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * instagram_posts → contents + publications tek seferlik data migrasyonu.
 *
 * - Transaction içinde çalışır; herhangi bir exception tüm insert'leri geri alır.
 * - Eski post ID'sinin yeni ID ile aynı olduğu ASLA varsayılmaz; eşlemeler
 *   transaction boyunca bellekte tutulur.
 * - Legacy tek-eksen kayıtlar (REELS/STORIES + media_product_type=null),
 *   2026_08_21_143102 migrasyonundaki map mantığının aynısıyla çözülür.
 * - Bilinmeyen status / media kombinasyonu / eksik hesap / geçersiz ürün
 *   durumlarında sessiz fallback YOK; exception fırlatılır.
 */
class InstagramPostsDataMigrator
{
    /**
     * Instagram'ın video kabul ettiği uzantılar (143102 migrasyonu ile aynı).
     */
    private const VIDEO_EXT_PATTERN = '/\.(mp4|mov|webm|avi|wmv|mpeg|mpg)(\?|$)/i';

    /**
     * @return array{0: int, 1: int} [contentCount, publicationCount]
     */
    public static function migratePostsToContentsAndPublications(): array
    {
        foreach (['contents', 'publications'] as $table) {
            $count = DB::table($table)->count();

            if ($count > 0) {
                throw new RuntimeException(
                    "Data migrasyonu yalnızca boş {$table} tablosu üzerinde çalışabilir (mevcut: {$count} kayıt).",
                );
            }
        }

        $contentCount = 0;
        $publicationCount = 0;

        DB::transaction(function () use (&$contentCount, &$publicationCount): void {
            // old_post_id → content_id / publication_id (transaction boyunca bellekte)
            $contentIdsByPostId = [];
            $publicationIdsByPostId = [];

            DB::table('instagram_posts')
                ->orderBy('id')
                ->chunkById(100, function ($posts) use (&$contentIdsByPostId, &$publicationIdsByPostId, &$contentCount, &$publicationCount): void {
                    foreach ($posts as $post) {
                        [$type, $surface] = self::resolveMediaType(
                            $post->media_type,
                            $post->media_product_type,
                            (string) $post->media_url,
                            (int) $post->id,
                        );

                        $productId = $post->product_id;

                        if ($productId !== null
                            && DB::table('products')->where('id', $productId)->doesntExist()
                        ) {
                            throw new RuntimeException(
                                "Post #{$post->id}: product_id={$productId} products tablosunda bulunamadı.",
                            );
                        }

                        $account = DB::table('instagram_accounts')
                            ->where('team_id', $post->team_id)
                            ->where('ig_user_id', $post->ig_user_id)
                            ->first();

                        if ($account === null) {
                            throw new RuntimeException(
                                "Post #{$post->id}: team_id={$post->team_id} + ig_user_id={$post->ig_user_id} "
                                .'için Instagram hesabı bulunamadı.',
                            );
                        }

                        $status = self::resolveStatus($post->status, (int) $post->id);

                        $contentId = DB::table('contents')->insertGetId([
                            'team_id' => $post->team_id,
                            'product_id' => $productId,
                            'type' => $type,
                            'surface' => $surface,
                            'caption' => $post->caption,
                            'media_url' => $post->media_url,
                            'thumbnail_url' => $post->thumbnail_url,
                            'children' => $post->children,
                            'alt_text' => $post->alt_text,
                            'story_link' => $post->story_link,
                            'first_comment' => $post->first_comment,
                            'is_ai_generated' => $post->is_ai_generated,
                            'created_at' => $post->created_at ?? now(),
                            'updated_at' => $post->updated_at ?? now(),
                        ]);

                        $publicationId = DB::table('publications')->insertGetId([
                            'team_id' => $post->team_id,
                            'content_id' => $contentId,
                            'instagram_account_id' => $account->id,
                            'ig_user_id' => $post->ig_user_id,
                            'status' => $status,
                            'scheduled_at' => $post->scheduled_at,
                            'published_at' => $post->published_at,
                            'ig_media_timestamp' => $post->ig_media_timestamp,
                            'container_id' => $post->container_id,
                            'media_id' => $post->media_id,
                            'permalink' => $post->permalink,
                            'error_message' => $post->error_message,
                            'created_at' => $post->created_at ?? now(),
                            'updated_at' => $post->updated_at ?? now(),
                        ]);

                        $contentIdsByPostId[$post->id] = $contentId;
                        $publicationIdsByPostId[$post->id] = $publicationId;
                        $contentCount++;
                        $publicationCount++;
                    }
                });
        });

        return [$contentCount, $publicationCount];
    }

    /**
     * instagram_post_insights.publication_id backfill.
     *
     * Eski post → publication eşlemesi media_id üzerinden kurulur
     * (media_id'ler benzersizdir; çift kayıt varsa exception fırlatılır).
     * Eski instagram_post_id kolonu, FK'sı ve index'i bilinçli olarak korunur.
     *
     * @return int Backfill edilen insight sayısı
     */
    public static function backfillInsightPublicationIds(): int
    {
        $backfilled = 0;

        DB::transaction(function () use (&$backfilled): void {
            DB::table('instagram_post_insights')
                ->whereNull('publication_id')
                ->orderBy('id')
                ->chunkById(500, function ($insights) use (&$backfilled): void {
                    foreach ($insights as $insight) {
                        $post = DB::table('instagram_posts')
                            ->where('id', $insight->instagram_post_id)
                            ->first();

                        if ($post === null) {
                            throw new RuntimeException(
                                "Insight #{$insight->id}: instagram_post_id={$insight->instagram_post_id} "
                                .'için eski post bulunamadı (orphan insight).',
                            );
                        }

                        $publications = DB::table('publications')
                            ->where('media_id', $post->media_id)
                            ->get();

                        if ($publications->count() !== 1) {
                            throw new RuntimeException(
                                "Insight #{$insight->id}: media_id={$post->media_id} için "
                                ."{$publications->count()} publication bulundu (1 bekleniyordu).",
                            );
                        }

                        DB::table('instagram_post_insights')
                            ->where('id', $insight->id)
                            ->update(['publication_id' => $publications->first()->id]);

                        $backfilled++;
                    }
                });
        });

        return $backfilled;
    }

    /**
     * 2026_08_21_143102 migrasyonundaki legacy map mantığının aynısı.
     *
     * @return array{0: string, 1: string} [type, surface]
     */
    public static function resolveMediaType(
        ?string $mediaType,
        ?string $mediaProductType,
        string $mediaUrl,
        int $postId,
    ): array {
        // Güncel iki-eksenli kayıtlar: bilinen kombinasyonlar birebir taşınır.
        if ($mediaProductType !== null) {
            return match ([$mediaType, $mediaProductType]) {
                ['VIDEO', 'REELS'] => ['VIDEO', 'REELS'],
                ['VIDEO', 'FEED'] => ['VIDEO', 'FEED'],
                ['IMAGE', 'FEED'] => ['IMAGE', 'FEED'],
                ['IMAGE', 'STORY'] => ['IMAGE', 'STORY'],
                ['CAROUSEL_ALBUM', 'FEED'] => ['CAROUSEL_ALBUM', 'FEED'],
                default => throw new RuntimeException(
                    "Post #{$postId}: bilinmeyen media_type/media_product_type kombinasyonu: "
                    ."{$mediaType} + {$mediaProductType}",
                ),
            };
        }

        // Legacy tek-eksenli kayıtlar (143102 migrasyonundaki map ile aynı):
        // STORIES için video tespiti media_url uzantısından yapılır.
        $isVideo = preg_match(self::VIDEO_EXT_PATTERN, $mediaUrl) === 1;

        return match ($mediaType) {
            'REELS' => $isVideo ? ['VIDEO', 'REELS'] : ['IMAGE', 'FEED'],
            'STORIES' => $isVideo ? ['VIDEO', 'STORY'] : ['IMAGE', 'STORY'],
            'IMAGE' => ['IMAGE', 'FEED'],
            'VIDEO' => ['VIDEO', 'REELS'],
            default => throw new RuntimeException(
                "Post #{$postId}: bilinmeyen legacy media_type: {$mediaType}",
            ),
        };
    }

    /**
     * Eski status değerleri Publication sabitleriyle birebir aynıdır;
     * bilinmeyen bir değer sessizce dönüştürülmez.
     */
    public static function resolveStatus(string $status, int $postId): string
    {
        $allowed = [
            Publication::STATUS_DRAFT,
            Publication::STATUS_SCHEDULED,
            Publication::STATUS_PUBLISHING,
            Publication::STATUS_PUBLISHED,
            Publication::STATUS_FAILED,
            Publication::STATUS_FLAGGED,
            Publication::STATUS_CANCELLED,
        ];

        if (! in_array($status, $allowed, true)) {
            throw new RuntimeException(
                "Post #{$postId}: bilinmeyen status değeri: {$status}",
            );
        }

        return $status;
    }
}
