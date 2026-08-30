<?php

namespace App\Console\Commands;

use App\Domain\Notification\Services\NotificationService;
use App\Models\InstagramAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Domain 4 — Süresi yaklaşan Instagram jetonları için hesap sahibine
 * Telegram üzerinden "yeniden bağla" uyarısı gönderir.
 *
 * Token yenileme komutundan (instagram:refresh-tokens) BAĞIMSIZ çalışır:
 * yenileme başarılıysa token süresi uzar ve hesap 7 günlük pencereden çıkar;
 * yenileme başarısızsa jeton pencerede kalır ve bu komut kullanıcıya bildirir.
 */
#[Signature('instagram:notify-expiring-tokens')]
#[Description('7 gün içinde süresi dolacak (ve yenilenememiş) Instagram jetonları için hesap sahibine Telegram bildirimi gönderir')]
class NotifyExpiringInstagramTokens extends Command
{
    private const WINDOW_DAYS = 7;

    public function handle(NotificationService $notifier): int
    {
        $accounts = InstagramAccount::query()
            ->whereNotNull('access_token')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(self::WINDOW_DAYS))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('Bildirilecek süresi dolan jeton yok.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($accounts as $account) {
            $team = $account->team;

            if (! $team || ! $this->shouldNotify($account)) {
                continue;
            }

            $notifier->notifyTokenExpiring(
                $team,
                $account->username ?? 'Instagram hesabı',
                $account->token_expires_at->format('d.m.Y'),
            );

            $account->forceFill(['token_expiry_notified_at' => now()])->save();

            $notified++;
            $this->info("Bildirildi: [#{$account->id}] @{$account->username}");
        }

        $this->table(['Durum', 'Adet'], [
            ['Bildirildi', $notified],
        ]);

        return self::SUCCESS;
    }

    /**
     * Aynı süre-son penceresi içinde tekrar tekrar bildirim göndermeyi önler.
     */
    protected function shouldNotify(InstagramAccount $account): bool
    {
        $windowStart = $account->token_expires_at->copy()->subDays(self::WINDOW_DAYS);

        return $account->token_expiry_notified_at === null
            || $account->token_expiry_notified_at->lt($windowStart);
    }
}
