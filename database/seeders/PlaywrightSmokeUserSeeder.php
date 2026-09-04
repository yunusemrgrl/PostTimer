<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Playwright smoke test kullanıcısı (scripts/dub-button-smoke.mjs).
 *
 * Kullanım:
 *   php artisan db:seed --class=PlaywrightSmokeUserSeeder
 *
 * super_admin rolü sayesinde User::getTenants() üzerinden TÜM tenant'lara
 * erişir; smoke testi tenant seçimini atlayıp doğrudan İçerikler'e gidebilir.
 *
 * UYARI: Şifre yalnızca yerel/e2e ortam içindir — bu seeder production'a
 * çalıştırılmaz (paketin parçası olarak DatabaseSeeder'dan ÇAĞRILMAZ).
 */
class PlaywrightSmokeUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'pw-smoke@example.com'],
            [
                'name' => 'PW Smoke',
                'password' => 'password', // 'hashed' cast otomatik hash'ler
            ],
        );

        $user->assignRole('super_admin');
    }
}
