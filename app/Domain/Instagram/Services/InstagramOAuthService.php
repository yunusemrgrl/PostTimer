<?php

namespace App\Domain\Instagram\Services;

use App\Models\InstagramAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
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
    /**
     * Hesap-bazlı refresh kilidi (saniye). Client timeout'u (instagram.timeout,
     * 30 sn) artı connect süresinden belirgin biçimde uzun olmalı: refresh
     * isteği platformca işlendikten sonra bizde zaman aşımı oluşursa yeni
     * jeton kaybedilir. ConnectionVerifier::REFRESH_LOCK_SECONDS deseninin
     * uyarlanışıdır; tek platform olduğundan sabit yeterlidir.
     */
    public const REFRESH_LOCK_SECONDS = 120;

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

    /**
     * Hesabın jetonunu eşzamanlılık korumalı yeniler (ConnectionVerifier
     * deseninin uyarlanışı): refresh çevresi Cache::lock ile sarılır, böylece
     * iki süreç aynı anda refresh isteği atıp jetonu düşüremez.
     *
     * Kilit süresi, client timeout'unun izin verdiğinden belirgin biçimde
     * uzundur — refresh isteği platform tarafından işlendikten sonra bizim
     * tarafımızda zaman aşımı olursa yeni token kaybedilir; kilidi kısa
     * tutup yarış açmaktansa uzun tutmayı tercih ederiz.
     *
     * Kilit başka bir süreçteyse null döner: o süreç taze jetonu kalıcı
     * hale getirecektir — çağıran taraf hesabı yeniden okumalıdır.
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    public function refreshAccountToken(InstagramAccount $account): ?array
    {
        $lock = Cache::lock(self::refreshLockKey($account), self::REFRESH_LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $result = $this->refreshLongLivedToken($account->access_token);

            $account->forceFill([
                'access_token' => $result['access_token'],
                'token_expires_at' => $result['expires_in'] > 0
                    ? now()->addSeconds($result['expires_in'])
                    : null,
                'token_expiry_notified_at' => null,
            ])->save();

            return $result;
        } finally {
            $lock->release();
        }
    }

    public static function refreshLockKey(InstagramAccount $account): string
    {
        return "ig-token-refresh-{$account->id}";
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
