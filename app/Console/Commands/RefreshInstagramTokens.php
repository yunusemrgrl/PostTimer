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

        foreach ($expiringAccounts as $account) {
            try {
                $result = $oauth->refreshLongLivedToken($account->access_token);

                $account->forceFill([
                    'access_token' => $result['access_token'],
                    'token_expires_at' => $result['expires_in'] > 0
                        ? now()->addSeconds($result['expires_in'])
                        : null,
                ])->save();

                $refreshed++;
                $this->info("Yenilendi: [#{$account->id}] @{$account->username}");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("Yenilenemedi: [#{$account->id}] {$exception->getMessage()}");
            }
        }

        $this->table(['Durum', 'Adet'], [
            ['Yenilendi', $refreshed],
            ['Başarısız', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
