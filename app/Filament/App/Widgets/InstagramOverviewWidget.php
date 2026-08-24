<?php

namespace App\Filament\App\Widgets;

use App\Models\Publication;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InstagramOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected ?string $heading = 'Instagram Genel Bakış';

    protected function getStats(): array
    {
        $team = Filament::getTenant();

        if (! $team) {
            return [];
        }

        $accounts = $team->instagramAccounts();
        $accountCount = (clone $accounts)->count();
        $followers = (clone $accounts)->sum('followers_count');

        $publications = Publication::query()
            ->whereBelongsTo($team, 'team');
        $published = (clone $publications)
            ->where('status', Publication::STATUS_PUBLISHED)
            ->where('published_at', '>=', now()->subDays(30))
            ->count();

        $scheduled = (clone $publications)
            ->where('status', Publication::STATUS_SCHEDULED)
            ->where('scheduled_at', '>', now())
            ->count();

        $failed = (clone $publications)
            ->where('status', Publication::STATUS_FAILED)
            ->count();

        $flagged = (clone $publications)
            ->where('status', Publication::STATUS_FLAGGED)
            ->count();

        return [
            Stat::make('Bağlı Hesap', $accountCount)
                ->description($followers.' takipçi')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),

            Stat::make('Son 30 Günde Yayın', $published)
                ->description('yayınlanan gönderi')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('success'),

            Stat::make('Zamanlanmış', $scheduled)
                ->description('bekleyen gönderi')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),

            Stat::make('Uyarıldı', $flagged)
                ->description('stok kontrol uyarısı')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($flagged > 0 ? 'warning' : 'gray'),

            Stat::make('Başarısız', $failed)
                ->description('tekrar denenebilir')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
