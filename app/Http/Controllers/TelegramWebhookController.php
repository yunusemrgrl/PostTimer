<?php

namespace App\Http\Controllers;

use App\Models\TelegramSetting;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Domain 4 — Telegram bot doğrulama akışı.
 *
 * 1. Kullanıcı panelde bot token girer → verification_code üretilir
 * 2. Kullanıcı Telegram'da bota /start <code> yazar
 * 3. Telegram webhook'a update gönderir → code eşleşirse chat_id kaydedilir
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    /**
     * Telegram'dan gelen webhook update'ini işler.
     * Doğrulama kodu eşleşirse chat_id kaydedilir.
     */
    public function webhook(Request $request, string $token): JsonResponse
    {
        $setting = TelegramSetting::query()
            ->where('webhook_secret', $token)
            ->first();

        if (! $setting) {
            return response()->json(['ok' => false], 404);
        }

        $parsed = $this->telegram->parseUpdate($request->json()->all());

        if (! $parsed['chat_id'] || ! $parsed['text']) {
            return response()->json(['ok' => true]);
        }

        // /start <verification_code> komutunu işle
        $text = trim($parsed['text']);
        $code = null;

        if (str_starts_with($text, '/start ')) {
            $code = trim(Str::after($text, '/start '));
        } elseif ($text === '/start') {
            // Sadece /start — hoşgeldin mesajı gönder
            $this->telegram->sendMessage(
                $setting->bot_token,
                $parsed['chat_id'],
                "Bot'a hoş geldiniz! Doğrulama için panelinizdeki kodu /start <kod> şeklinde gönderin.",
            );

            return response()->json(['ok' => true]);
        }

        if ($code && $setting->verification_code && hash_equals($setting->verification_code, $code)) {
            $setting->forceFill([
                'chat_id' => $parsed['chat_id'],
                'is_verified' => true,
                'verification_code' => null,
            ])->save();

            $this->telegram->sendMessage(
                $setting->bot_token,
                $parsed['chat_id'],
                '✅ Doğrulama başarılı! Artık PostTimer uyarılarını bu sohbette alacaksınız.',
            );
        }

        return response()->json(['ok' => true]);
    }
}
