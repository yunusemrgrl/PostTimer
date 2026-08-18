<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instagram_posts', function (Blueprint $table) {
            // Domain 1 ↔ Domain 2 ilişkisi: post ↔ product
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Story: Link Sticker URL
            $table->text('story_link')->nullable()->after('media_url');

            // Reels/Post/Karusel: Otomatik ilk yorum
            $table->text('first_comment')->nullable()->after('story_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_posts', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'story_link', 'first_comment']);
        });
    }
};
