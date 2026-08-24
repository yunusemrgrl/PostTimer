<?php

namespace App\Console\Commands;

use App\Events\PublicationFlagged;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Services\InstagramPublishingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Yayın-öncesi proaktif bağlantı sağlık kontrolü (TryPost'un
 * CheckUpcomingPostConnections + VerifyUpcomingPostConnections ikilisinin
 * tek-platform sadeleştirmesi; AGPL — yalnızca davranış deseni esinlenmiştir).
 *
 * Önümüzdeki 1 saatte yayınlanacak scheduled Publication'ların hesapları,
 * ucuz bir Graph okuma çağrısıyla doğrulanır:
 *
 * - Hesap erişilemezse yayınlara flagged çekilir + PublicationFlagged ile
 *   Telegram uyarısı gider (mevcut notification altyapısı).
 * - Hesap başına 60 dk cooldown: ölü token, her 15 dk'lık tick'te tekrar
 *   uyarı spam'i üretmez. Cooldown içinde yeni riskli yayınlar görülmez —
 *   bilinçli takas (TryPost'un re-notify cooldown mantığının karşılığı).
 */
#[Signature('publications:check-connections')]
#[Description('Yayına 1 saatten az kalan yayınların Instagram hesaplarını doğrular')]
class CheckUpcomingPublicationConnections extends Command
{
    private const LOOKAHEAD_MINUTES = 60;

    private const WARNING_COOLDOWN_MINUTES = 60;

    public function handle(): int
    {
        $publications = Publication::query()
            ->where('status', Publication::STATUS_SCHEDULED)
            ->whereBetween('scheduled_at', [now(), now()->addMinutes(self::LOOKAHEAD_MINUTES)])
            ->get();

        if ($publications->isEmpty()) {
            $this->info('Kontrol edilecek yaklaşan yayın yok.');

            return self::SUCCESS;
        }

        // Aynı hesaba ait yayınlar tek Graph çağrısıyla değerlendirilir.
        $byAccount = $publications->groupBy('instagram_account_id');

        $flagged = 0;
        $healthy = 0;
        $skipped = 0;

        foreach ($byAccount as $accountId => $accountPublications) {
            /** @var Publication $first */
            $first = $accountPublications->first();

            $account = InstagramAccount::query()->find($accountId);

            if (! $account) {
                continue;
            }

            if (Cache::has($this->cooldownKey($account))) {
                $skipped += $accountPublications->count();
                $this->line("[@{$account->username}] Cooldown içinde — atlandı");

                continue;
            }

            if ($this->isConnectionHealthy($first)) {
                $healthy += $accountPublications->count();
                $this->info("[@{$account->username}] Sağlıklı");

                continue;
            }

            foreach ($accountPublications as $publication) {
                $publication->forceFill([
                    'status' => Publication::STATUS_FLAGGED,
                    'error_message' => 'Instagram hesabı yayın öncesi sağlık kontrolünde erişilemedi; jeton/bağlantıyı kontrol edin.',
                ])->save();

                PublicationFlagged::dispatch($publication->fresh(), 'Yayın öncesi bağlantı kontrolü başarısız');
                $flagged++;
            }

            Cache::put($this->cooldownKey($account), true, now()->addMinutes(self::WARNING_COOLDOWN_MINUTES));

            $this->warn("[@{$account->username}] ERİŞİLEMEZ — {$accountPublications->count()} yayın flagged moduna alındı");
        }

        $this->table(['Durum', 'Adet'], [
            ['Sağlıklı', $healthy],
            ['Uyarıldı', $flagged],
            ['Cooldown atlandı', $skipped],
        ]);

        return self::SUCCESS;
    }

    /**
     * Ucuz doğrulama: hesabın kendi jetonuyla profil okuma çağrısı.
     * 401/400/timeout dahil her hata "bağlantı sağlıksız" sayılır.
     */
    private function isConnectionHealthy(Publication $publication): bool
    {
        try {
            InstagramPublishingService::forAccount($publication->instagramAccount)
                ->getAccount($publication->ig_user_id, ['username']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cooldownKey(InstagramAccount $account): string
    {
        return "ig-conn-warning-{$account->id}";
    }
}
