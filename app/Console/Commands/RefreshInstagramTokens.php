<?php

namespace App\Console\Commands;

use App\Models\InstagramAccount;
use App\Services\InstagramOAuthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('instagram:refresh-tokens')]
#[Description('Yakında süresi dolacak Instagram uzun ömürlü jetonlarını 60 gün daha yeniler')]
class RefreshInstagramTokens extends Command
{
    public function handle(InstagramOAuthService $oauth): int
    {
        $expiringAccounts = InstagramAccount::query()
            ->whereNotNull('access_token')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(7))
            ->get();

        if ($expiringAccounts->isEmpty()) {
            $this->info('Yenilenecek jeton yok.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($expiringAccounts as $account) {
            try {
                $result = $oauth->refreshAccountToken($account);

                if ($result === null) {
                    // Başka bir süreç zaten yeniliyor — taze jetonu o yazacak.
                    $skipped++;
                    $this->line("Atlandı (kilitli, başka süreç yeniliyor): [#{$account->id}] @{$account->username}");

                    continue;
                }

                $refreshed++;
                $this->info("Yenilendi: [#{$account->id}] @{$account->username}");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Yenilenemedi: [#{$account->id}] {$exception->getMessage()}");
            }
        }

        $this->table(['Durum', 'Adet'], [
            ['Yenilendi', $refreshed],
            ['Kilitli (atlandı)', $skipped],
            ['Başarısız', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
