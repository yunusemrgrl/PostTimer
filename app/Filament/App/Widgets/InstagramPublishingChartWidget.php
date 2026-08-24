<?php

namespace App\Filament\App\Widgets;

use App\Models\Publication;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class InstagramPublishingChartWidget extends ChartWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Yayın Akışı (14 gün)';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $team = Filament::getTenant();

        if (! $team) {
            return [
                'datasets' => [
                    ['label' => 'Yayınlanan', 'data' => []],
                    ['label' => 'Planlanan', 'data' => []],
                ],
                'labels' => [],
            ];
        }

        $days = collect(range(13, -1))->map(fn (int $daysAgo) => now()->subDays($daysAgo)->startOfDay());

        $published = Publication::query()
            ->whereBelongsTo($team, 'team')
            ->where('status', Publication::STATUS_PUBLISHED)
            ->whereBetween('published_at', [$days->first(), $days->last()->copy()->endOfDay()])
            ->get(['published_at']);

        $scheduled = Publication::query()
            ->whereBelongsTo($team, 'team')
            ->where('status', Publication::STATUS_SCHEDULED)
            ->whereBetween('scheduled_at', [$days->first(), $days->last()->copy()->endOfDay()])
            ->get(['scheduled_at']);

        return [
            'datasets' => [
                [
                    'label' => 'Yayınlanan',
                    'data' => $days
                        ->map(fn ($day) => $published
                            ->filter(fn (Publication $p) => $p->published_at?->isSameDay($day))
                            ->count())
                        ->all(),
                    'borderColor' => '#4caf50',
                    'fill' => true,
                ],
                [
                    'label' => 'Planlanan',
                    'data' => $days
                        ->map(fn ($day) => $scheduled
                            ->filter(fn (Publication $p) => $p->scheduled_at?->isSameDay($day))
                            ->count())
                        ->all(),
                    'borderColor' => '#3f51b5',
                ],
            ],
            'labels' => $days->map(fn ($day) => $day->format('d.m'))->all(),
        ];
    }
}
