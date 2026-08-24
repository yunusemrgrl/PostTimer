<?php

use App\Support\InstagramPostsDataMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * instagram_post_insights.publication_id ekleme + backfill.
 *
 * - publication_id nullable + nullOnDelete FK (publications).
 * - Backfill: eski post → media_id → publication eşlemesiyle 36/36 (tümü) doldurulur;
 *   map edilemeyen tek insight varsa migrasyon exception fırlatır.
 * - Eski instagram_post_id kolonu, FK'sı ve index'i BİLİNÇLİ OLARAK KORUNUR —
 *   FK swap ve legacy rename ileriki release'lerde ayrı yapılacaktır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_post_insights', function (Blueprint $table) {
            $table->foreignId('publication_id')
                ->nullable()
                ->constrained('publications')
                ->nullOnDelete();

            // İleride hedeflenen composite index (eski index korunur)
            $table->index(['publication_id', 'metric', 'fetched_at']);
        });

        InstagramPostsDataMigrator::backfillInsightPublicationIds();
    }

    public function down(): void
    {
        Schema::table('instagram_post_insights', function (Blueprint $table) {
            $table->dropIndex(['publication_id', 'metric', 'fetched_at']);
            $table->dropConstrainedForeignId('publication_id');
        });
    }
};
