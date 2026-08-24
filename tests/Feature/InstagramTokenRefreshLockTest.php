<?php

use App\Models\InstagramAccount;
use App\Services\InstagramOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function expiringAccount(): InstagramAccount
{
    return InstagramAccount::factory()->create([
        'access_token' => 'old-long-lived-token',
        'token_expires_at' => now()->addDays(3),
    ]);
}

it('refreshes the account token under a lock and persists it', function () {
    Http::fake([
        'graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'fresh-token',
            'expires_in' => 5184000,
        ]),
    ]);

    $account = expiringAccount();
    $oauth = app(InstagramOAuthService::class);

    $result = $oauth->refreshAccountToken($account);

    expect($result)
        ->not->toBeNull()
        ->access_token->toBe('fresh-token');

    expect($account->fresh())
        ->access_token->toBe('fresh-token')
        ->token_expiry_notified_at->toBeNull();

    // Kilit release edildi — yeniden alınabilmeli.
    expect(Cache::lock(InstagramOAuthService::refreshLockKey($account), 10)->get())->toBeTrue();
});

it('skips the refresh and persists nothing when another process holds the lock', function () {
    $account = expiringAccount();
    $oauth = app(InstagramOAuthService::class);

    // Başka bir sürecin kilidi tutuyor.
    $foreignLock = Cache::lock(InstagramOAuthService::refreshLockKey($account), 60);
    $foreignLock->get();

    Http::fake(['*' => Http::response(['access_token' => 'should-not-happen'])]);

    $result = $oauth->refreshAccountToken($account);

    expect($result)->toBeNull();

    Http::assertNothingSent();

    // Hesap dokunulmadan kaldı.
    expect($account->fresh())
        ->access_token->toBe('old-long-lived-token');

    $foreignLock->release();
});

it('releases the lock even when the refresh request fails', function () {
    Http::fake([
        'graph.instagram.com/refresh_access_token*' => Http::response(['error' => ['message' => 'invalid token']], 400),
    ]);

    $account = expiringAccount();
    $oauth = app(InstagramOAuthService::class);

    expect(fn () => $oauth->refreshAccountToken($account))->toThrow(RequestException::class);

    // Hata sonrası kilit takılı kalmamalı.
    expect(Cache::lock(InstagramOAuthService::refreshLockKey($account), 10)->get())->toBeTrue();

    expect($account->fresh()->access_token)->toBe('old-long-lived-token');
});
