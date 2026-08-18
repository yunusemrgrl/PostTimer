<?php

namespace App\Console\Commands;

use App\Jobs\PublishScheduledPost;
use App\Models\InstagramPost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Zamanı gelmiş postları bulur ve her biri için ayrı bir
 * PublishScheduledPost job'ı dispatch eder. Yayın async çalışır —
 * komut 1 saniye içinde biter, worker'lar paralel yayınlar.
 *
 * Idempotency: ShouldBeUnique job ID sayesinde aynı post iki kez
 * dispatch edilse bile kuyrukta tek job kalır.
 */
#[Signature('instagram:publish-scheduled')]
#[Description('Zamanı gelmiş postları kuyruğa dispatch eder (async publish)')]
class PublishScheduledInstagramPosts extends Command
{
    public function handle(): int
    {
        $duePosts = InstagramPost::query()
            ->where('status', InstagramPost::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($duePosts->isEmpty()) {
            $this->info('Yayınlanacak zamanlanmış gönderi yok.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($duePosts as $post) {
            PublishScheduledPost::dispatch($post);
            $dispatched++;

            $this->info("Kuyruğa dispatch edildi: [#{$post->id}] {$post->caption}");
        }

        $this->info("Toplam {$dispatched} gönderi kuyruğa dispatch edildi.");

        return self::SUCCESS;
    }
}
