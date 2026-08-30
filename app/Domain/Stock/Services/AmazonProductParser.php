<?php

namespace App\Domain\Stock\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Domain 1 — Amazon ürün linklerinden ASIN, ürün başlığı ve kapak
 * görselini çıkarır. Open Graph meta etiketlerini kullanır; bot
 * korumasına takılırsa boş döner ve kullanıcı manuel girer (hibrit model).
 */
class AmazonProductParser
{
    /**
     * Amazon ASIN pattern: 10 karakter, alfanümerik büyük harf.
     */
    protected const ASIN_PATTERN = '/(?:\/dp\/|\/product\/|amzn\.(?:com|to)\/)([A-Z0-9]{10})/i';

    /**
     * @return array{asin: ?string, title: ?string, image_url: ?string}
     */
    public function parse(string $url): array
    {
        $asin = $this->extractAsin($url);

        if (! $asin) {
            return ['asin' => null, 'title' => null, 'image_url' => null];
        }

        $meta = $this->fetchOpenGraph($url);

        return [
            'asin' => $asin,
            'title' => $meta['title'] ?? null,
            'image_url' => $meta['image_url'] ?? null,
        ];
    }

    /**
     * URL'den ASIN çıkarır ( Amazon dp/product/amzn.to pattern).
     */
    public function extractAsin(string $url): ?string
    {
        if (preg_match(self::ASIN_PATTERN, $url, $matches)) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    /**
     * Amazon sayfasını fetch edip og:title ve og:image meta'larını çıkarır.
     * Bot korumasına takılırsa sessizce null döner — kullanıcı manuel girer.
     *
     * @return array{title: ?string, image_url: ?string}
     */
    protected function fetchOpenGraph(string $url): array
    {
        try {
            $html = $this->client()->get($url)->body();
        } catch (Throwable) {
            return ['title' => null, 'image_url' => null];
        }

        return [
            'title' => $this->extractMeta($html, 'og:title') ?? $this->extractTitleTag($html),
            'image_url' => $this->extractMeta($html, 'og:image'),
        ];
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

    protected function extractMeta(string $html, string $property): ?string
    {
        $pattern = '/<meta\s+[^>]*property=["\']'.preg_quote($property, '/').'["\']\s+content=["\']([^"\']+)["\']/i';

        if (preg_match($pattern, $html, $matches)) {
            return trim(html_entity_decode($matches[1]));
        }

        return null;
    }

    protected function extractTitleTag(string $html): ?string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1]));

            return preg_replace('/\s*[:\-–|]\s*Amazon.*$/i', '', $title) ?? $title;
        }

        return null;
    }
}
