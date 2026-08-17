<?php

namespace App\Console\Commands;

use App\Models\InstagramPost;
use App\Services\PublishInstagramPostService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('instagram:publish-scheduled')]
#[Description('Zamanı gelmiş zamanlanmış Instagram gönderilerini yayınlar')]
class PublishScheduledInstagramPosts extends Command
{
    public function handle(PublishInstagramPostService $publisher): int
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

        $published = 0;
        $failed = 0;

        foreach ($duePosts as $post) {
            try {
                $publisher->publish($post);
                $published++;

                $this->info("Yayınlandı: [#{$post->id}] {$post->caption}");
            } catch (Throwable $exception) {
                $failed++;

                $this->error("Yayınlanamadı: [#{$post->id}] {$exception->getMessage()}");
            }
        }

        $this->table(['Durum', 'Adet'], [
            ['Yayınlandı', $published],
            ['Başarısız', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
