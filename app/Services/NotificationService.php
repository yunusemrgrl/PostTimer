<?php

namespace App\Services;

use App\Models\Team;
use Throwable;

/**
 * Domain 4 — Olay tabanlı bildirim merkezi.
 *
 * Stok kontrol (Domain 3) bir gönderiyi flagged moduna aldığında
 * veya yayın (Domain 2) başarısız olduğunda, bu servis
 * takımın Telegram botuna anlık uyarı gönderir.
 *
 * Telegram yapılandırılmamışsa sessizce atlanır — ana akış bozulmaz.
 */
class NotificationService
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    /**
     * Takıma Telegram üzerinden uyarı gönderir.
     * Bot yapılandırılmamışsa veya gönderim başarısızsa ana akışı bozmaz.
     */
    public function notifyTeam(Team $team, string $message): void
    {
        $setting = $team->telegramSetting;

        if (! $setting || ! $setting->isConfigured()) {
            return;
        }

        try {
            $this->telegram->sendMessage(
                $setting->bot_token,
                $setting->chat_id,
                $message,
            );
        } catch (Throwable) {
            // Telegram bildirimi başarısız olsa da ana akış devam eder.
            // Hata log'a düşer ama işlem durmaz.
        }
    }

    /**
     * Stok kontrol uyarısı: gönderi flagged moduna alındı.
     */
    public function notifyStockFlagged(Team $team, string $postCaption, string $reason): void
    {
        $this->notifyTeam(
            $team,
            "⚠️ <b>Stok Uyarısı</b>\n\n"
            ."Gönderi: <i>{$postCaption}</i>\n"
            ."Durum: {$reason}\n\n"
            .'Gönderi yayından kaldırıldı. Kontrol edip yeniden zamanlayın.',
        );
    }

    /**
     * Yayın başarısız uyarısı.
     */
    public function notifyPublishFailed(Team $team, string $postCaption, string $error): void
    {
        $this->notifyTeam(
            $team,
            "❌ <b>Yayın Başarısız</b>\n\n"
            ."Gönderi: <i>{$postCaption}</i>\n"
            ."Hata: {$error}\n\n"
            .'Gönderiyi kontrol edip tekrar deneyin.',
        );
    }

    /**
     * Token süresi dolma uyarısı.
     */
    public function notifyTokenExpiring(Team $team, string $username, string $expiryDate): void
    {
        $this->notifyTeam(
            $team,
            "🔑 <b>Jeton Süresi Doluyor</b>\n\n"
            ."Hesap: @{$username}\n"
            ."Son kullanma: {$expiryDate}\n\n"
            .'Hesabı yeniden bağlayın.',
        );
    }
}
