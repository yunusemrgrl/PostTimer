<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Domain 4 — Telegram Bot API istemcisi.
 * Belirli bir bot token ve chat ID'ye mesaj gönderir.
 */
class TelegramBotService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function sendMessage(string $botToken, int $chatId, string $text): array
    {
        if (empty($botToken) || empty($chatId)) {
            throw new RuntimeException('Telegram bot token veya chat ID eksik.');
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post(self::API_BASE."/{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false,
            ]);

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
