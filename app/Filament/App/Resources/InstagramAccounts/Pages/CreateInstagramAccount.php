<?php

namespace App\Filament\App\Resources\InstagramAccounts\Pages;

use App\Filament\App\Resources\InstagramAccounts\InstagramAccountResource;
use App\Services\InstagramAccountService;
use App\Services\InstagramOAuthService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Throwable;

class CreateInstagramAccount extends CreateRecord
{
    protected static string $resource = InstagramAccountResource::class;

    /**
     * Kayıt oluşturulduktan sonra:
     * 1. Kısa ömürlü token'ı 60 günlük uzun ömürlü jetona çevirir (başarısız olursa orijinal token kalır)
     * 2. Profil bilgilerini Instagram API'den senkronize eder
     * Her iki adım da hata verse hesap kaydı geçerlidir; "Yenile" ile tekrar denenebilir.
     */
    protected function afterCreate(): void
    {
        $account = $this->getRecord();

        $this->exchangeToLongLivedToken($account);
        $this->syncProfile($account);
    }

    protected function exchangeToLongLivedToken($account): void
    {
        if (! config('instagram.client_secret')) {
            return;
        }

        try {
            $longLived = app(InstagramOAuthService::class)
                ->exchangeForLongLivedToken($account->access_token);

            $account->forceFill([
                'access_token' => $longLived['access_token'],
                'token_expires_at' => $longLived['expires_in'] > 0
                    ? now()->addSeconds($longLived['expires_in'])
                    : null,
            ])->save();

            Notification::make()
                ->title('Erişim jetonu 60 günlük uzun ömürlü jetona çevrildi')
                ->success()
                ->send();
        } catch (Throwable) {
            // Token zaten uzun ömürlü olabilir veya exchange yapılamıyor;
            // orijinal token ile devam et.
        }
    }

    protected function syncProfile($account): void
    {
        try {
            app(InstagramAccountService::class)->sync($account);
        } catch (Throwable) {
            // Profil senkronizasyonu başarısız olsa da hesap kaydı geçerlidir.
        }
    }
}
