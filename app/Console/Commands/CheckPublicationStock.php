<?php

namespace App\Console\Commands;

use App\Events\PublicationFlagged;
use App\Models\Publication;
use App\Services\AmazonStockChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Just-In-Time stok kontrolünün Publication karşılığı.
 *
 * Yayına yaklaşan zamanlanmış yayınların bağlı ürünlerinin stok durumunu
 * kontrol eder; stoksuz/tükendi/404 tespit edilirse yayını "flagged"
 * moduna çeker. Aynı ürünü kullanan yayınlar tek kontrolle değerlendirilir
 * (ürün başına bir Amazon isteği) — Product kaydının kendisine dokunulmaz.
 */
#[Signature('publications:check-stock')]
#[Description('Zamanlanmış yayınların bağlı ürünlerinin stok durumunu JIT kontrol eder')]
class CheckPublicationStock extends Command
{
    private const CHECK_WINDOW_MINUTES = 20;

    public function handle(AmazonStockChecker $checker): int
    {
        $publications = Publication::query()
            ->where('status', Publication::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now()->addMinutes(self::CHECK_WINDOW_MINUTES))
            ->where('scheduled_at', '>', now())
            ->whereHas('content', fn ($query) => $query->whereNotNull('product_id'))
            ->with(['content.product'])
            ->get();

        if ($publications->isEmpty()) {
            $this->info('Kontrol edilecek zamanlanmış yayın yok.');

            return self::SUCCESS;
        }

        // Aynı ürünü kullanan yayınlar tek Amazon isteğiyle değerlendirilir.
        $resultsByProduct = [];

        $flagged = 0;
        $inStock = 0;

        foreach ($publications as $publication) {
            $product = $publication->content?->product;

            // Product yoksa (silinmiş/null) yayını olduğu gibi bırak.
            if (! $product) {
                continue;
            }

            $result = $resultsByProduct[$product->id]
                ??= $checker->check($product);

            if ($result['status'] === 'in_stock') {
                $inStock++;
                $this->info("[#{$publication->id}] Stokta: {$product->title}");

                continue;
            }

            // Stoksuz / 404 / hata → yayını flagged moduna çek.
            $publication->forceFill([
                'status' => Publication::STATUS_FLAGGED,
                'error_message' => $result['message'],
            ])->save();

            // Domain 4: Telegram uyarısı event listener'ı ile gönderilir.
            PublicationFlagged::dispatch($publication->fresh(), $result['message']);

            $flagged++;
            $this->warn("[#{$publication->id}] UYARI: {$result['message']} — yayın flagged moduna alındı");
        }

        $this->table(['Durum', 'Adet'], [
            ['Stokta', $inStock],
            ['Uyarıldı', $flagged],
        ]);

        return self::SUCCESS;
    }
}
