<?php

namespace App\Filament\App\Widgets;

use App\Models\InstagramPost;
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

        $posts = $team->instagramPosts();
        $published = (clone $posts)
            ->where('status', InstagramPost::STATUS_PUBLISHED)
            ->where('published_at', '>=', now()->subDays(30))
            ->count();

        $scheduled = (clone $posts)
            ->where('status', InstagramPost::STATUS_SCHEDULED)
            ->where('scheduled_at', '>', now())
            ->count();

        $failed = (clone $posts)
            ->where('status', InstagramPost::STATUS_FAILED)
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

            Stat::make('Başarısız', $failed)
                ->description('tekrar denenebilir')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
