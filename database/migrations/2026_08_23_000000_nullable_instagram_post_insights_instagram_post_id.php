<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * instagram_post_insights.instagram_post_id NOT NULL'duruyor; Faz B1'de
 * publication-side insights (yalnızca publication_id) kaydedilir. Eski
 * legacy domain'ın veriyi korulup yeni publication kayıtlarların
 * instagram_post_id'sız oluşmaya şağlamaktır.
 *
 * Additive: mevcut 36/36 insight kaydın instagram_post_id değeri aynen korunur.
 * FK swap / legacy rename / eski kolon'u drop — ileriki fazlarda ayrı yapılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_post_insights', function (Blueprint $table) {
            $table->foreignId('instagram_post_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('instagram_post_insights', function (Blueprint $table) {
            $table->foreignId('instagram_post_id')->change();
        });
    }
};
