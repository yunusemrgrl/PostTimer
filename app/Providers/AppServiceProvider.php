<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /**
         * ÖNEMLİ: super_admin rolüne sahip kullanıcılar, uygulamadaki
         * TÜM Gate/Policy kontrollerini otomatik olarak geçer.
         *
         * Bu satır, "yönetici bütün hesaplara erişip yönetebilsin"
         * isteğinin temelini oluşturur: TeamPolicy, TeamMemberPolicy
         * veya ileride eklenecek başka policy'ler ayrı ayrı süper admin
         * kontrolü yazmak zorunda kalmaz.
         */
        Gate::before(function ($user) {
            return $user->isSuperAdmin() ? true : null;
        });

        // Sadece 'production' değil; local/testing DIŞINDAKİ her ortamda
        // (staging, qa, preview, vs.) config eksikliğini erken yakalıyoruz.
        if (! app()->environment(['local', 'testing']) && blank(config('app.media_tenant_hash_key'))) {
            throw new RuntimeException('MEDIA_TENANT_HASH_KEY tanımlı değil — uygulama başlatılamıyor.');
        }
    }
}
