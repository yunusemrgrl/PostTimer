<?php

namespace App\Filament\App\Resources\InstagramAccounts\Pages;

use App\Filament\App\Resources\InstagramAccounts\InstagramAccountResource;
use App\Services\InstagramAccountService;
use Filament\Resources\Pages\CreateRecord;
use Throwable;

class CreateInstagramAccount extends CreateRecord
{
    protected static string $resource = InstagramAccountResource::class;

    /**
     * Kayıt oluşturulduktan sonra profil bilgilerini API'den çeker.
     * API hatası kaydı engellemez; kullanıcı "Yenile" ile tekrar deneyebilir.
     */
    protected function afterCreate(): void
    {
        try {
            app(InstagramAccountService::class)->sync($this->getRecord());
        } catch (Throwable) {
            // Profil senkronizasyonu başarısız olsa da hesap kaydı geçerlidir.
        }
    }
}
