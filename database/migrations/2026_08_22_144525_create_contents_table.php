<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content domain'i: Bir ürüne bağlı, hesap-bağımsız yeniden
     * kullanılabilir içerik varlığı. Yayın-kaydı publication tablosundadır;
     * bu tablo yalnızca içeriğin kendisini saklar.
     */
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // İçerik formatı (IMAGE / VIDEO / CAROUSEL_ALBUM)
            $table->string('type')->default('IMAGE');

            // Yayın yüzü (FEED / REELS / STORY) — Meta media_product_type değerleri
            $table->string('surface')->default('FEED');

            $table->text('caption')->nullable();
            $table->text('media_url')->nullable();
            $table->text('thumbnail_url')->nullable();

            // Carousel child medya listesi (mevcut instagram_posts.children formatıyla uyumlu)
            $table->json('children')->nullable();

            $table->text('alt_text')->nullable();

            // Story link sticker URL
            $table->text('story_link')->nullable();

            // Affiliate link genelde ilk yorumda taşınır
            $table->text('first_comment')->nullable();

            $table->boolean('is_ai_generated')->default(false);
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
