<?php

namespace App\Domain\Stock\Services;

use App\Models\Product;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Domain 3 — Amazon ürün sayfasını çeker ve stok/fiyat
 * durumunu değerlendirir. "Stok tükendi", "Sayfa bulunamadı"
 * veya fiyat anomalisi tespit ederse uyarı döner.
 */
class AmazonStockChecker
{
    /**
     * Stok tükendi / kullanılamaz pattern'leri (Amazon TR/COM).
     */
    protected const OUT_OF_STOCK_PATTERNS = [
        '/stok\s*tükendi/iu',
        '/şu\s*anda\s*stokta\s*yok/iu',
        '/stokta\s*yok/iu',
        '/currently\s*unavailable/i',
        '/out\s*of\s*stock/i',
        '/in\s*stock\s*soon/i',
        '/no\s*longer\s*available/i',
        '/ürün\s*kaldırılmış/iu',
        '/discontinued/i',
    ];

    /**
     * Sayfa bulunamadı pattern'leri.
     */
    protected const NOT_FOUND_PATTERNS = [
        '/sayfa\s*bulunamadı/iu',
        '/page\s*not\s*found/i',
        '/404/',
        '/<title>[^<]*404/i',
    ];

    /**
     * @return array{status: string, message: string}
     */
    public function check(Product $product): array
    {
        try {
            $html = $this->client()->get($product->url)->body();
        } catch (Throwable) {
            return [
                'status' => 'error',
                'message' => 'Ürün sayfasına erişilemedi (ağ hatası veya bot koruması).',
            ];
        }

        if ($this->matches($html, self::NOT_FOUND_PATTERNS)) {
            return [
                'status' => 'not_found',
                'message' => 'Ürün sayfası bulunamadı (404) — link geçersiz olabilir.',
            ];
        }

        if ($this->matches($html, self::OUT_OF_STOCK_PATTERNS)) {
            return [
                'status' => 'out_of_stock',
                'message' => 'Ürün stokta yok — gönderi yayından önce kontrol edin.',
            ];
        }

        return [
            'status' => 'in_stock',
            'message' => 'Ürün stokta mevcut.',
        ];
    }

    /**
     * HTML'i pattern listesine karşı kontrol eder.
     *
     * @param  array<int, string>  $patterns
     */
    protected function matches(string $html, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html)) {
                return true;
            }
        }

        return false;
    }

    protected function client(): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
                'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
            ]);
    }
}
