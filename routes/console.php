<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * 7 gün içinde süresi dolacak uzun ömürlü Instagram jetonlarını
 * günlük olarak yeniler (60 gün daha). Hesap altyapısı ortak —
 * Publication domain'ine bağımsız çalışır.
 */
Schedule::command('instagram:refresh-tokens')
    ->daily()
    ->at('03:00')
    ->withoutOverlapping();

/*
 * Yenileme sonrası hâlâ 7 gün içinde kalan jetonlar için hesap
 * sahibine Telegram bildirimi gönderir. Hesap altyapısı ortak kalır.
 */
Schedule::command('instagram:notify-expiring-tokens')
    ->daily()
    ->at('03:30')
    ->withoutOverlapping();

/*
 * Zamanı gelmiş Publication'ları her dakika yayınlar.
 */
Schedule::command('publications:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Just-In-Time stok kontrolünü Publication karşılığı — mevcut
 * Instagram stok kontrolüyle aynı sıklıkta çalışır.
 */
Schedule::command('publications:check-stock')
    ->everyFiveMinutes()
    ->withoutOverlapping();
