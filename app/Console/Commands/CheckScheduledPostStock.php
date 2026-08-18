<?php

namespace App\Console\Commands;

use App\Events\PostFlagged;
use App\Models\InstagramPost;
use App\Services\AmazonStockChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Domain 3 — Just-In-Time stok kontrolü.
 *
 * Sadece yayına 20 dakika以内 olan zamanlanmış postların
 * bağlı olduğu ürünlerin stok durumunu kontrol eder.
 * Stoksuz/tükendi tespit ederse postu "flagged" moduna çeker
 * ve yayını önler (Domain 4 Telegram uyarısı tetiklenecek).
 */
#[Signature('instagram:check-stock')]
#[Description('Zamanlanmış postların bağlı ürünlerinin stok durumunu JIT kontrol eder')]
class CheckScheduledPostStock extends Command
{
    private const CHECK_WINDOW_MINUTES = 20;

    public function handle(AmazonStockChecker $checker): int
    {
        $posts = InstagramPost::query()
            ->where('status', InstagramPost::STATUS_SCHEDULED)
            ->whereNotNull('product_id')
            ->where('scheduled_at', '<=', now()->addMinutes(self::CHECK_WINDOW_MINUTES))
            ->where('scheduled_at', '>', now())
            ->with('product')
            ->get();

        if ($posts->isEmpty()) {
            $this->info('Kontrol edilecek zamanlanmış post yok.');

            return self::SUCCESS;
        }

        $flagged = 0;
        $inStock = 0;

        foreach ($posts as $post) {
            $result = $checker->check($post->product);

            if ($result['status'] === 'in_stock') {
                $inStock++;
                $this->info("[#{$post->id}] Stokta: {$post->product->title}");

                continue;
            }

            // Stoksuz / 404 / hata → postu flagged moduna çek
            $post->forceFill([
                'status' => InstagramPost::STATUS_FLAGGED,
                'error_message' => $result['message'],
            ])->save();

            // Domain 4: Event-driven Telegram uyarısı
            PostFlagged::dispatch($post->fresh(), $result['message']);

            $flagged++;
            $this->warn("[#{$post->id}] UYARI: {$result['message']} — post flagged moduna alındı");
        }

        $this->table(['Durum', 'Adet'], [
            ['Stokta', $inStock],
            ['Uyarıldı', $flagged],
        ]);

        return self::SUCCESS;
    }
}
