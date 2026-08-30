<?php

namespace App\Console\Commands;

use App\Domain\Notification\Services\NotificationService;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Yayın hattının tek bakışta sağlık raporu. Scheduler'ın sessiz
 * çalıştığı üç komutun son çalışma durumunu, takılı yayınları,
 * yaklaşan planı ve token riskini özetler. Sorun varsa FAILURE ile
 * çıkar (cron/monitoring entegrasyonu için).
 */
#[Signature('publications:health {--notify : Sorun varsa Telegram uyarısı da gönder}')]

#[Description('Yayın hattı sağlık raporu (takılı yayınlar, plan, token, son çalışmalar)')]
class PublicationsHealth extends Command
{
    public function handle(): int
    {
        $stuck = Publication::query()
            ->where('status', Publication::STATUS_PUBLISHING)
            ->where('updated_at', '<=', now()->subHour())
            ->count();

        $upcoming = Publication::query()
            ->where('status', Publication::STATUS_SCHEDULED)
            ->whereBetween('scheduled_at', [now(), now()->addDay()])
            ->count();

        $expiringTokens = InstagramAccount::query()
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(7))
            ->pluck('username')
            ->all();

        $commands = ['publications:publish-scheduled', 'publications:recover-stuck', 'publications:check-connections'];

        $lastRuns = collect($commands)
            ->map(fn (string $command): array => [
                'command' => $command,
                'state' => Cache::get("sched:last-run:{$command}", 'unknown'),
            ]);

        foreach ($lastRuns as $run) {
            $this->line(sprintf('[%s] %s', $run['state'], $run['command']));
        }

        $this->table(['Metrik', 'Değer'], [
            ['Takılı publishing (>1 saat)', $stuck],
            ['Önümüzdeki 24 saatteki yayınlar', $upcoming],
            ['Token süresi yaklaşan hesaplar', implode(', ', $expiringTokens) ?: '—'],
        ]);

        $problems = $stuck > 0
            || $lastRuns->contains(fn (array $run) => $run['state'] === 'failure');

        if (! $problems) {
            $this->info('Sorun görünmüyor.');

            return self::SUCCESS;
        }

        if ($this->option('notify')) {
            self::notifyTeams("❗ publications:health sorun tespit etti: {$stuck} takılı yayın, son çalışmalarda failure var.");
        }

        return self::FAILURE;
    }

    /**
     * Scheduler ->onSuccess/onFailure callback'lerinden çağrılır: son
     * çalışma durumunu Cache'e yazar; failure'da Telegram'a tek satır
     * uyarı gönderir (takımı olan her hesaba).
     */
    public static function recordRun(string $command, bool $success): void
    {
        Cache::put(
            "sched:last-run:{$command}",
            $success ? 'ok' : 'failure',
            now()->addDays(2),
        );

        if (! $success) {
            self::notifyTeams("❗ Scheduler komutu başarısız oldu: {$command}");
        }
    }

    private static function notifyTeams(string $message): void
    {
        $service = app(NotificationService::class);

        Team::query()
            ->whereHas('telegramSetting')
            ->with('telegramSetting')
            ->each(fn (Team $team) => $service->notifyTeam($team, $message));
    }
}
