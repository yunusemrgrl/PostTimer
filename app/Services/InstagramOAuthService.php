<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Business Login for Instagram (Instagram Login) OAuth akışı.
 *
 * 1. Kullanıcı instagram.com/oauth/authorize adresine yönlendirilir.
 * 2. Dönen authorization code, kısa ömürlü User access token'a
 *    dönüştürülür (api.instagram.com).
 * 3. Kısa ömürlü token, 60 gün geçerli uzun ömürlü token'a
 *    dönüştürülür (graph.instagram.com).
 */
class InstagramOAuthService
{
    protected string $authorizeUrl = 'https://www.instagram.com/oauth/authorize';

    protected string $shortLivedTokenUrl = 'https://api.instagram.com/oauth/access_token';

    protected string $longLivedTokenUrl = 'https://graph.instagram.com/access_token';

    protected string $refreshTokenUrl = 'https://graph.instagram.com/refresh_access_token';

    public function getRedirectUrl(string $state): string
    {
        $this->assertConfigured();

        $params = http_build_query([
            'client_id' => config('instagram.client_id'),
            'redirect_uri' => route('instagram.callback'),
            'response_type' => 'code',
            'scope' => implode(',', config('instagram.scopes')),
            'state' => $state,
        ]);

        return $this->authorizeUrl.'?'.$params;
    }

    /**
     * Authorization code'u kısa ömürlü token'a dönüştürür.
     *
     * @return array{access_token: string, user_id: string}
     */
    public function exchangeCodeForShortLivedToken(string $code): array
    {
        $this->assertConfigured();

        $response = $this->client(true)
            ->post($this->shortLivedTokenUrl, [
                'client_id' => config('instagram.client_id'),
                'client_secret' => config('instagram.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => route('instagram.callback'),
                'code' => $code,
            ]);

        $data = $response->throw()->json();

        // API yanıtı {data: [{access_token, user_id, permissions}]} şeklindedir.
        $payload = $data['data'][0] ?? $data;

        if (empty($payload['access_token']) || empty($payload['user_id'])) {
            throw new RuntimeException('Instagram token değişimi başarısız: beklenen alanlar dönmedi.');
        }

        return [
            'access_token' => (string) $payload['access_token'],
            'user_id' => (string) $payload['user_id'],
        ];
    }

    /**
     * Kısa ömürlü token'ı 60 gün geçerli uzun ömürlü token'a dönüştürür.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $this->assertConfigured();

        $response = $this->client()
            ->get($this->longLivedTokenUrl, [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => config('instagram.client_secret'),
                'access_token' => $shortLivedToken,
            ]);

        $data = $response->throw()->json();

        if (empty($data['access_token'])) {
            throw new RuntimeException('Instagram uzun ömürlü token alınamadı.');
        }

        return [
            'access_token' => (string) $data['access_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 0),
        ];
    }

    /**
     * Geçerli bir uzun ömürlü token'ı 60 gün daha yeniler.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function refreshLongLivedToken(string $longLivedToken): array
    {
        $response = $this->client()
            ->get($this->refreshTokenUrl, [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $longLivedToken,
            ]);

        $data = $response->throw()->json();

        if (empty($data['access_token'])) {
            throw new RuntimeException('Instagram token yenilenemedi.');
        }

        return [
            'access_token' => (string) $data['access_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 0),
        ];
    }

    protected function client(bool $asMultipart = false): PendingRequest
    {
        $request = Http::timeout((int) config('instagram.timeout', 30));

        if ($asMultipart) {
            $request = $request->asMultipart();
        }

        return $request;
    }

    protected function assertConfigured(): void
    {
        if (! config('instagram.client_id') || ! config('instagram.client_secret')) {
            throw new RuntimeException('Business Login ayarları eksik: INSTAGRAM_CLIENT_ID ve INSTAGRAM_CLIENT_SECRET tanımlanmalı.');
        }
    }
}
