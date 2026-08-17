<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Zamanı gelmiş Instagram gönderilerini her dakika yayınlar.
 * Üst üste binmeyi engellemek için withoutOverlapping kullanılır.
 */
Schedule::command('instagram:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * 7 gün içinde süresi dolacak uzun ömürlü Instagram jetonlarını
 * günlük olarak yeniler (60 gün daha).
 */
Schedule::command('instagram:refresh-tokens')
    ->daily()
    ->at('03:00')
    ->withoutOverlapping();
