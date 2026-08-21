<?php

namespace App\Http\Controllers;

use App\Models\TelegramSetting;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Domain 4 — Telegram bot doğrulama akışı (tek bot, tek endpoint).
 *
 * @posttimer_cloud_bot tek bottur. Telegram, tüm güncellemeleri tek
 * webhook adresine (POST /telegram/webhook) gönderir.
 *
 * İlk bağlamada chat_id henüz kayıtlı olmadığı için tenant eşleştirmesi
 * `verification_code` üzerinden yapılır; eşleşen setting'in chat_id'si
 * kaydedilir ve is_verified=true yapılır.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function webhook(Request $request): JsonResponse
    {
        $parsed = $this->telegram->parseUpdate($request->json()->all());

        if (! $parsed['chat_id'] || ! $parsed['text']) {
            return response()->json(['ok' => true]);
        }

        $text = trim($parsed['text']);

        if ($text === '/start') {
            $this->telegram->sendMessage(
                null,
                $parsed['chat_id'],
                'PostTimer\'a hoş geldiniz! Bildirim almaya başlamak için paneldeki doğrulama kodunu /start KOD şeklinde gönderin.',
            );

            return response()->json(['ok' => true]);
        }

        $setting = null;

        if (str_starts_with($text, '/start ')) {
            $code = trim(Str::after($text, '/start '));

            $setting = TelegramSetting::query()
                ->where('verification_code', $code)
                ->first();
        }

        if ($setting) {
            $setting->forceFill([
                'chat_id' => $parsed['chat_id'],
                'is_verified' => true,
                'verification_code' => null,
            ])->save();

            $this->telegram->sendMessage(
                null,
                $parsed['chat_id'],
                '✅ Doğrulama başarılı! Artık PostTimer uyarılarını bu sohbette alacaksınız.',
            );
        }

        return response()->json(['ok' => true]);
    }
}
