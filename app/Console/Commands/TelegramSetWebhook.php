<?php

namespace App\Console\Commands;

use App\Domain\Notification\Services\TelegramBotService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Domain 4 — PostTimer botunun webhook adresini Telegram'a kaydeder.
 *
 * Tek bot (@posttimer_cloud_bot) tek webhook endpoint'ine sahiptir;
 * bu yüzden webhook kaydı tenant başına değil, GLOBAL olarak bir kez yapılır.
 */
#[Signature('telegram:set-webhook')]
#[Description('PostTimer botunun webhook adresini Telegram\'a kaydeder (tek bot, tek endpoint)')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramBotService $telegram): int
    {
        $url = route('telegram.webhook');

        try {
            $result = $telegram->setWebhook(null, $url);
        } catch (Throwable $e) {
            $this->error("Webhook kaydedilemedi: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! ($result['ok'] ?? false)) {
            $this->error('Webhook kaydedilemedi: '.($result['description'] ?? 'Bilinmeyen hata'));

            return self::FAILURE;
        }

        $this->info("Webhook kaydedildi → {$url}");

        return self::SUCCESS;
    }
}
