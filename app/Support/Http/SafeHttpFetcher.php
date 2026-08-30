<?php

namespace App\Support\Http;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Kullanıcı sağlanan harici URL'leri indirmek için SSRF-korumalı fetcher
 * (TryPost SafeHttpFetcher deseninin karşılığı; kod kopyalanmamıştır).
 *
 * Guard'lar:
 * - Yalnızca http/https şeması.
 * - Her host (redirect hop'ları dahil) DNS çözümlenir; private/reserved/
 *   link-local/multicast IP'ler reddedilir (169.254.169.254 metadata vb.).
 * - Redirect'ler elle takip edilir (max 3) — her hop yeniden guard'dan geçer.
 * - İndirme boyutu cap'i aşılırsa istek anında iptal edilir (bütün dosya
 *   belleğe/diske alınmaz).
 */
class SafeHttpFetcher
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        protected int $maxBytes = 52428800, // 50 MB
    ) {}

    /**
     * URL'i güvenli biçimde indirip geçici dosyaya yazar.
     *
     * @return array{path: string, mime: ?string, bytes: int}
     *
     * @throws RuntimeException Guard ihlali veya indirme hatası durumunda.
     */
    public function fetchToTempFile(string $url): array
    {
        $this->assertSafeUrl($url);

        $temp = tempnam(sys_get_temp_dir(), 'url-import-');

        if ($temp === false) {
            throw new RuntimeException('Geçici dosya oluşturulamadı.');
        }

        try {
            return $this->download($url, $temp, 0);
        } catch (Throwable $e) {
            @unlink($temp);

            throw $e;
        }
    }

    protected function download(string $url, string $temp, int $depth): array
    {
        if ($depth > self::MAX_REDIRECTS) {
            throw new RuntimeException('Çok fazla yönlendirme (max '.self::MAX_REDIRECTS.').');
        }

        $response = Http::timeout(30)
            ->withOptions([
                'allow_redirects' => false,
                'progress' => function ($total, $downloaded): void {
                    if ($downloaded > $this->maxBytes) {
                        throw new RuntimeException('Dosya boyutu sınırı aşıldı ('.$this->maxBytes.' bayt).');
                    }
                },
            ])
            ->get($url);

        // Redirect zinciri — her hop yeniden guard'dan geçer.
        if ($response->status() >= 300 && $response->status() < 400) {
            $location = $response->header('Location');

            if ($location === '') {
                throw new RuntimeException('Yönlendirme başlığı boş.');
            }

            $next = $this->absoluteUrl($url, $location);
            $this->assertSafeUrl($next);

            return $this->download($next, $temp, $depth + 1);
        }

        if (! $response->successful()) {
            throw new RuntimeException("İndirme başarısız: HTTP {$response->status()} ($url)");
        }

        $body = (string) $response->getBody();
        $bytes = strlen($body);

        if ($bytes === 0) {
            throw new RuntimeException('Boş yanıt gövdesi.');
        }

        if (file_put_contents($temp, $body) === false) {
            throw new RuntimeException('Geçici dosyaya yazılamadı.');
        }

        return [
            'path' => $temp,
            'mime' => mime_content_type($temp) ?: null,
            'bytes' => $bytes,
        ];
    }

    /**
     * Şema ve host IP'si güvenli mi? (Redirect hedefleri dahil her URL için çağrılır.)
     */
    protected function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException("İzin verilmeyen URL: yalnızca http(s) kabul edilir ($url)");
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP)
            ? $host
            : gethostbyname($host);

        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException("Host çözümlenemedi: $host");
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException("Private/reserved IP engellendi: $host → $ip");
        }
    }

    protected function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($location, '/')
            ? $origin.$location
            : $origin.'/'.ltrim($location, '/');
    }
}
