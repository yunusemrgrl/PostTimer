<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta'nın IG Media modeline uygun iki eksenli domain modeli:
     *   media_type         = içerik formatı  (IMAGE, VIDEO, CAROUSEL_ALBUM)
     *   media_product_type  = yayın yüzü     (FEED, REELS, STORY)
     *
     * Ayrıca API'den okunan medya metadatasını saklamak için kolonlar.
     */
    public function up(): void
    {
        Schema::table('instagram_posts', function (Blueprint $table) {
            $table->string('media_product_type')->nullable()->after('media_type');
            $table->string('permalink')->nullable()->after('media_id');
            $table->text('thumbnail_url')->nullable()->after('media_url');
            $table->unsignedInteger('like_count')->nullable();
            $table->unsignedInteger('comments_count')->nullable();
            $table->timestamp('ig_media_timestamp')->nullable()->after('published_at');
        });

        // --- Geriye dönük data migration ---
        // Eski tek-eksenli media_type değerlerini iki eksenli modele map et.
        $mappings = [
            'IMAGE' => ['media_type' => 'IMAGE', 'media_product_type' => 'FEED'],
            'VIDEO' => ['media_type' => 'VIDEO', 'media_product_type' => 'REELS'],
            'REELS' => ['media_type' => 'VIDEO', 'media_product_type' => 'REELS'],
            'CAROUSEL' => ['media_type' => 'CAROUSEL_ALBUM', 'media_product_type' => 'FEED'],
            // STORIES: media_url'den dosya uzantısına bakılarak image/video tespit edilir.
        ];

        foreach ($mappings as $old => $new) {
            DB::table('instagram_posts')
                ->where('media_type', $old)
                ->update($new);
        }

        // STORIES → media_url'den image/video tespiti, sonra IMAGE/VIDEO + STORY
        $stories = DB::table('instagram_posts')->where('media_type', 'STORIES')->get(['id', 'media_url']);

        foreach ($stories as $story) {
            $isVideo = $story->media_url
                && preg_match('/\.(mp4|mov|webm|avi|wmv|mpeg|mpg)(\?|$)/i', $story->media_url) === 1;

            DB::table('instagram_posts')
                ->where('id', $story->id)
                ->update([
                    'media_type' => $isVideo ? 'VIDEO' : 'IMAGE',
                    'media_product_type' => 'STORY',
                ]);
        }
    }

    public function down(): void
    {
        // Eski değerlere geri map et (best-effort; STORY kayıtları image/video
        // ayrımı kaybolur, STORIES olarak geri yazılır).
        $reverse = [
            ['media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'to' => 'IMAGE'],
            ['media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'to' => 'VIDEO'],
            ['media_type' => 'CAROUSEL_ALBUM', 'media_product_type' => 'FEED', 'to' => 'CAROUSEL'],
            ['media_type' => 'IMAGE', 'media_product_type' => 'STORY', 'to' => 'STORIES'],
            ['media_type' => 'VIDEO', 'media_product_type' => 'STORY', 'to' => 'STORIES'],
        ];

        foreach ($reverse as $r) {
            DB::table('instagram_posts')
                ->where('media_type', $r['media_type'])
                ->where('media_product_type', $r['media_product_type'])
                ->update(['media_type' => $r['to'], 'media_product_type' => null]);
        }

        Schema::table('instagram_posts', function (Blueprint $table) {
            $table->dropColumn([
                'media_product_type',
                'permalink',
                'thumbnail_url',
                'like_count',
                'comments_count',
                'ig_media_timestamp',
            ]);
        });
    }
};
