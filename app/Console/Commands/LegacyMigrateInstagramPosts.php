<?php

namespace App\Console\Commands;

use App\Support\InstagramPostsDataMigrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * instagram_posts → contents + publications tek seferlik geçiş komutu.
 * InstagramPostsDataMigrator'ı sarmalar:
 *
 * - --force OLMADAN dry-run çalışır: mevcut durum raporlanır, HİÇBİR ŞEY yazılmaz.
 * - --force ile migrator transaction içinde çalışır; herhangi bir exception
 *   tüm insert'leri geri alır (migrator garantisi) ve komut FAILURE döner.
 * - Idempotent guard: contents/publications boş değilse reddeder.
 */
#[Signature('instagram:legacy-migrate {--force : Geçişi gerçekten uygula (yoksa dry-run)}')]
#[Description('Legacy instagram_posts verisini contents + publications tablolarına taşır')]
class LegacyMigrateInstagramPosts extends Command
{
    public function handle(): int
    {
        $postCount = DB::table('instagram_posts')->count();
        $contentCount = DB::table('contents')->count();
        $publicationCount = DB::table('publications')->count();

        $this->table(['Tablo', 'Kayıt'], [
            ['instagram_posts (kaynak)', $postCount],
            ['contents', $contentCount],
            ['publications', $publicationCount],
        ]);

        if ($contentCount > 0 || $publicationCount > 0) {
            $this->error('Geçiş yalnızca boş contents/publications tablolarında çalışabilir. Veri zaten taşınmış olabilir.');

            return self::FAILURE;
        }

        if ($postCount === 0) {
            $this->info('Taşınacak legacy post yok.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->info("DRY-RUN: {$postCount} legacy post contents + publications'a taşınacaktır.");
            $this->line('Uygulamak için: php artisan instagram:legacy-migrate --force');

            return self::SUCCESS;
        }

        try {
            [$contentCount, $publicationCount] = InstagramPostsDataMigrator::migratePostsToContentsAndPublications();
            $backfilled = InstagramPostsDataMigrator::backfillInsightPublicationIds();
        } catch (Throwable $exception) {
            $this->error('Geçiş BAŞARISIZ oldu ve tamamen geri alındı: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Taşındı: {$contentCount} content, {$publicationCount} publication; {$backfilled} insight publication_id'si bağlandı.");

        return self::SUCCESS;
    }
}
