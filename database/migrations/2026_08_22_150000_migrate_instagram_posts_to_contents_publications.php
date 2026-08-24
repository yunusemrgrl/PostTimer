<?php

use App\Support\InstagramPostsDataMigrator;
use Illuminate\Database\Migrations\Migration;

/**
 * Data copy: instagram_posts → contents + publications.
 *
 * - Transaction içinde, chunkById ile çalışır (bkz. InstagramPostsDataMigrator).
 * - Eski post ID'si ile yeni ID'ler arasındaki eşleme transaction boyunca
 *   bellekte tutulur; tabloya geçici kolon eklenmez.
 * - Herhangi bir exception durumunda tüm insert'ler rollback olur.
 * - instagram_posts tablosu bu migrasyonda silinmez/boşaltılmaz — legacy
 *   veri sonraki bir release'te rename edilerek korunacaktır.
 */
return new class extends Migration
{
    public function up(): void
    {
        InstagramPostsDataMigrator::migratePostsToContentsAndPublications();
    }

    public function down(): void
    {
        // Data copy geri-alınamaz: contents/publications bilinçli olarak
        // dolu bırakılır; eski veri instagram_posts tablosunda durmaya devam eder.
    }
};
