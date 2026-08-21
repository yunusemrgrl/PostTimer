<?php

namespace App\Services;

use App\Models\InstagramAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class InstagramPublishingService
{
    /**
     * @param  int  $statusAttempts  How many times to poll a container's status (once per minute, max 5).
     * @param  int  $statusSleepMs  Milliseconds to wait between container status polls.
     */
    public function __construct(
        protected string $token,
        protected string $apiVersion = 'v25.0',
        protected string $host = 'graph.facebook.com',
        protected string $ruploadHost = 'rupload.facebook.com',
        protected int $timeout = 30,
        protected int $connectTimeout = 10,
        protected int $uploadTimeout = 600,
        protected int $statusAttempts = 5,
        protected int $statusSleepMs = 60000,
    ) {}

    /**
     * Hesap bazlı istemci: yalnızca bu hesabın kendi jetonu ve host'u
     * kullanılır. Global/jeton düşme (fallback) YOKTUR; jetonsuz hesap
     * açık bir hata fırlatır.
     */
    public static function forAccount(InstagramAccount $account): static
    {
        if (empty($account->access_token)) {
            throw new RuntimeException("@{$account->username} hesabının erişim jetonu yok; önce hesabı bağlayın.");
        }

        return new static(
            token: $account->access_token,
            apiVersion: (string) config('instagram.api_version'),
            host: (string) $account->api_host,
            ruploadHost: (string) config('instagram.rupload_host'),
            timeout: (int) config('instagram.timeout'),
            connectTimeout: (int) config('instagram.connect_timeout'),
            uploadTimeout: (int) config('instagram.upload_timeout'),
            statusAttempts: (int) config('instagram.status_attempts'),
            statusSleepMs: (int) config('instagram.status_sleep'),
        );
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createMediaContainer(string $igUserId, array $payload): array
    {
        $data = array_merge([
            'access_token' => $this->token,
        ], $payload);

        $response = $this->http()->post("https://{$this->host}/{$this->apiVersion}/{$igUserId}/media", $data);

        return $response->throw()->json();
    }

    /**
     * Create a media container for a local or publicly hosted video that
     * will be uploaded over a resumable upload session.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createResumableUploadSession(string $igUserId, string $mediaType, array $payload = []): array
    {
        return $this->createMediaContainer($igUserId, array_merge($payload, [
            'media_type' => $mediaType,
            'upload_type' => 'resumable',
        ]));
    }

    /**
     * @param  array<string, mixed>  $children  Container IDs of the carousel items.
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCarouselContainer(string $igUserId, array $children, array $payload = []): array
    {
        $data = array_merge([
            'access_token' => $this->token,
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
        ], $payload);

        $response = $this->http()->post("https://{$this->host}/{$this->apiVersion}/{$igUserId}/media", $data);

        return $response->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function publishMedia(string $igUserId, string $creationId): array
    {
        $response = $this->http()->post("https://{$this->host}/{$this->apiVersion}/{$igUserId}/media_publish", [
            'access_token' => $this->token,
            'creation_id' => $creationId,
        ]);

        return $response->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getContainerStatus(string $containerId): array
    {
        $response = $this->http()->get("https://{$this->host}/{$this->apiVersion}/{$containerId}", [
            'fields' => 'status_code',
            'access_token' => $this->token,
        ]);

        return $response->throw()->json();
    }

    /**
     * Poll a video container's status until it is ready to be published.
     * Meta recommends polling once per minute, for no more than 5 minutes.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the container errors, expires, or never finishes in time.
     */
    public function waitForContainerToFinish(string $containerId, ?callable $onPoll = null): array
    {
        $attempt = 0;
        $start = hrtime(true);

        return retry($this->statusAttempts, function () use ($containerId, $onPoll, &$attempt, $start): array {
            $attempt++;
            $statusResponse = $this->getContainerStatus($containerId);
            $statusCode = (string) ($statusResponse['status_code'] ?? 'unknown');

            if ($onPoll) {
                $onPoll($attempt, $statusCode, (int) round((hrtime(true) - $start) / 1e6));
            }

            if (in_array($statusCode, ['FINISHED', 'PUBLISHED'], true)) {
                return $statusResponse;
            }

            throw new RuntimeException(
                "Instagram container {$containerId} is not ready to publish (status: {$statusCode}).",
            );
        }, $this->statusSleepMs);
    }

    /**
     * Domain 2: Yayınlanmış bir medyaya yorum atar (otomatik ilk yorum).
     *
     * @return array<string, mixed>
     */
    public function createComment(string $mediaId, string $message): array
    {
        $response = $this->http()->post("https://{$this->host}/{$this->apiVersion}/{$mediaId}/comments", [
            'message' => $message,
            'access_token' => $this->token,
        ]);

        return $response->throw()->json();
    }

    /**
     * Hesap profil bilgilerini getirir.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function getAccount(string $igUserId, array $fields = [
        'username',
        'name',
        'account_type',
        'biography',
        'website',
        'followers_count',
        'media_count',
        'profile_picture_url',
    ]): array
    {
        $response = $this->http()->get("https://{$this->host}/{$this->apiVersion}/{$igUserId}", [
            'fields' => implode(',', $fields),
            'access_token' => $this->token,
        ]);

        return $response->throw()->json();
    }

    /**
     * Hesabın yayınlanmış medyalarını getirir.
     *
     * @return array<string, mixed>
     */
    public function getAccountMedia(string $igUserId, int $limit = 25): array
    {
        $response = $this->http()->get("https://{$this->host}/{$this->apiVersion}/{$igUserId}/media", [
            'fields' => 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp,like_count,comments_count',
            'limit' => $limit,
            'access_token' => $this->token,
        ]);

        return $response->throw()->json();
    }

    /**
     * Belirli bir medyanın alanlarını getirir.
     *
     * @return array<string, mixed>
     */
    public function getMedia(string $mediaId, string $fields = 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp,like_count,comments_count'): array
    {
        $response = $this->http()->get("https://{$this->host}/{$this->apiVersion}/{$mediaId}", [
            'fields' => $fields,
            'access_token' => $this->token,
        ]);

        return $response->throw()->json();
    }

    /**
     * Bir medya için insights metriklerini getirir.
     *
     * @param  array<int, string>  $metrics
     * @return array<string, mixed>
     */
    public function getMediaInsights(string $mediaId, array $metrics): array
    {
        $response = $this->http()->get("https://{$this->host}/{$this->apiVersion}/{$mediaId}/insights", [
            'metric' => implode(',', $metrics),
            'access_token' => $this->token,
        ]);

        return $response->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPublishingLimit(string $igUserId): array
    {
        $response = $this->http()->get("https://{$this->host}/{$this->apiVersion}/{$igUserId}/content_publishing_limit", [
            'fields' => 'quota_usage,config',
            'access_token' => $this->token,
        ]);

        return $response->throw()->json();
    }

    /**
     * Whether the account still has API publishing quota left for the
     * current 24-hour moving period (carousels count as a single post).
     */
    public function isWithinPublishingLimit(string $igUserId): bool
    {
        $limit = $this->getPublishingLimit($igUserId);
        $entry = $limit['data'][0] ?? throw new RuntimeException('Instagram yayın limiti bilgisi alınamadı.');

        $quotaUsed = (int) ($entry['quota_usage'] ?? throw new RuntimeException('Instagram yayın limiti bilgisi eksik (quota_usage).'));
        $quotaTotal = (int) ($entry['config']['quota_total'] ?? throw new RuntimeException('Instagram yayın limiti bilgisi eksik (quota_total).'));

        return $quotaUsed < $quotaTotal;
    }

    /**
     * Upload a local video file to a resumable upload session container.
     *
     * @return array<string, mixed>
     */
    public function uploadVideoFile(string $containerId, string $filePath, int $offset = 0): array
    {
        $fileSize = filesize($filePath);

        if ($fileSize === false) {
            throw new RuntimeException("Unable to determine the size of {$filePath}.");
        }

        $response = $this->http($this->uploadTimeout)
            ->withHeaders([
                'Authorization' => "OAuth {$this->token}",
                'offset' => (string) $offset,
                'file_size' => (string) $fileSize,
            ])
            ->withBody(
                file_get_contents($filePath) ?: '',
                'application/octet-stream',
            )
            ->post("https://{$this->ruploadHost}/ig-api-upload/{$this->apiVersion}/{$containerId}");

        return $response->throw()->json();
    }

    /**
     * Upload a publicly hosted video to a resumable upload session container.
     *
     * @return array<string, mixed>
     */
    public function uploadVideoFromUrl(string $containerId, string $fileUrl): array
    {
        $response = $this->http($this->uploadTimeout)
            ->withHeaders([
                'Authorization' => "OAuth {$this->token}",
                'file_url' => $fileUrl,
            ])
            ->post("https://{$this->ruploadHost}/ig-api-upload/{$this->apiVersion}/{$containerId}");

        return $response->throw()->json();
    }

    protected function http(?int $timeout = null): PendingRequest
    {
        return Http::acceptJson()
            ->timeout($timeout ?? $this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->beforeSending(function (Request $request) {
                Log::debug('Instagram API REQUEST', [
                    'method' => $request->method(),
                    'url' => $this->sanitizeUrl($request->url()),
                    'body' => $this->sanitizeBody($request->body()),
                ]);
            });
    }


    protected function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach ([
                         'access_token',
                         'client_secret',
                         'token',
                         'secret',
                     ] as $key) {
                unset($query[$key]);
            }

            $parts['query'] = http_build_query($query);
        }

        $result = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        $result .= $parts['path'] ?? '';

        if (! empty($parts['query'])) {
            $result .= '?'.$parts['query'];
        }

        return $result;
    }

    protected function sanitizeBody(?string $body): mixed
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $json = json_decode($body, true);

        if (! is_array($json)) {
            return $body;
        }

        foreach ([
                     'access_token',
                     'client_secret',
                     'token',
                     'secret',
                     'Authorization',
                     'authorization',
                 ] as $key) {
            if (array_key_exists($key, $json)) {
                $json[$key] = '[REDACTED]';
            }
        }

        return $json;
    }
}
