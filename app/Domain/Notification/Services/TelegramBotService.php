<?php

namespace App\Domain\Notification\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Domain 4 — Telegram Bot API istemcisi.
 * Belirli bir bot token ve chat ID'ye mesaj gönderir.
 */
class TelegramBotService
{
    // Telegram Bot API: https://api.telegram.org/bot{TOKEN}/{method} (bot ile token arasında slash YOK)
    private const API_BASE = 'https://api.telegram.org';

    /**
     * Açıkça token verilmediğinde config'teki varsayılan (env) token kullanılır.
     */
    public function __construct(
        protected ?string $botToken = null,
    ) {
        $this->botToken ??= config('services.telegram.bot_token');
    }

    public function sendMessage(?string $botToken, int $chatId, string $text): array
    {
        $botToken = $botToken ?: $this->botToken;

        if (empty($botToken) || empty($chatId)) {
            throw new RuntimeException('Telegram bot token veya chat ID eksik.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post(self::API_BASE."/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false,
            ]);

        return $response->throw()->json();
    }

    /**
     * Telegram'ın bu bot için webhook adresini kaydeder.
     * Telegram, kaydedilen URL'ye update'leri POST eder.
     */
    public function setWebhook(?string $botToken, string $url): array
    {
        $botToken = $botToken ?: $this->botToken;

        if (empty($botToken)) {
            throw new RuntimeException('Telegram bot token eksik.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post(self::API_BASE."/bot{$botToken}/setWebhook", [
                'url' => $url,
            ]);

        return $response->throw()->json();
    }

    /**
     * Botun mevcut webhook durumunu döner.
     */
    public function getWebhookInfo(?string $botToken): array
    {
        $botToken = $botToken ?: $this->botToken;

        if (empty($botToken)) {
            throw new RuntimeException('Telegram bot token eksik.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->get(self::API_BASE."/bot{$botToken}/getWebhookInfo");

        return $response->throw()->json();
    }

    /**
     * Bot'a gönderilen /start komutundan chat ID'yi çıkarır.
     * Webhook payload'dan alınır.
     *
     * @param  array<string, mixed>  $update
     * @return array{chat_id: ?int, text: ?string}
     */
    public function parseUpdate(array $update): array
    {
        $message = $update['message'] ?? null;

        if (! $message) {
            return ['chat_id' => null, 'text' => null];
        }

        return [
            'chat_id' => $message['chat']['id'] ?? null,
            'text' => $message['text'] ?? null,
        ];
    }
}
