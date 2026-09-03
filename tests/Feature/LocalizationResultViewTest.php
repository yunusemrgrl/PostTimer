<?php

use App\Domain\Video\Enums\LocalizationStatus;
use App\Models\Content;
use App\Models\Team;
use App\Models\VideoLocalization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Yerelleştirme sonuç view'ı tüm akış durumlarında (stepper dahil)
 * hatasız render edilmeli; segment/dublaj bölümleri koşullu çalışır.
 */
it('renders the localization flow view for every status', function (string $status) {
    $team = Team::factory()->create();
    $content = Content::factory()->reels()->create(['team_id' => $team->id]);

    $localization = VideoLocalization::query()->create([
        'team_id' => $team->id,
        'content_id' => $content->id,
        'status' => $status,
        'target_language' => 'tr',
        'translation' => ['segments' => [['start' => 0, 'end' => 2, 'source' => 'hello', 'translation' => 'merhaba']]],
    ]);

    $html = view('filament.video-localization-result', ['localization' => $localization])->render();

    expect($html)
        ->toContain('Gemini Analizi')
        ->toContain('Seslendirme')
        ->toContain('merhaba');
})->with([
    LocalizationStatus::Pending->value,
    LocalizationStatus::Analyzing->value,
    LocalizationStatus::Analyzed->value,
    LocalizationStatus::Voicing->value,
    LocalizationStatus::Completed->value,
    LocalizationStatus::Failed->value,
]);

it('polls while a run is in progress and stops when finished', function () {
    $team = Team::factory()->create();
    $content = Content::factory()->reels()->create(['team_id' => $team->id]);

    $running = VideoLocalization::query()->create([
        'team_id' => $team->id,
        'content_id' => $content->id,
        'status' => LocalizationStatus::Analyzing,
        'target_language' => 'tr',
    ]);

    expect(view('filament.video-localization-result', ['localization' => $running])->render())
        ->toContain('wire:poll.4s');

    $finished = $running->fresh()->forceFill(['status' => LocalizationStatus::Completed]);

    expect(view('filament.video-localization-result', ['localization' => $finished])->render())
        ->not->toContain('wire:poll.4s');
});
