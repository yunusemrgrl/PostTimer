<?php

use App\Console\Commands\PublicationsHealth;
use App\Models\Content;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Http::fake(['*' => Http::response()]);
});

it('reports healthy when there is nothing wrong', function () {
    Publication::factory()->published()->create();

    $this->artisan('publications:health')->assertSuccessful();
});

it('fails when a publication is stuck in publishing for over an hour', function () {
    Publication::factory()->create([
        'status' => Publication::STATUS_PUBLISHING,
        'updated_at' => now()->subHours(2),
    ]);

    $this->artisan('publications:health')->assertFailed();
});

it('fails when a scheduler command last recorded a failure', function () {
    PublicationsHealth::recordRun('publications:publish-scheduled', false);

    $this->artisan('publications:health')->assertFailed();
});

it('records scheduler run state into the cache', function () {
    PublicationsHealth::recordRun('publications:recover-stuck', true);
    PublicationsHealth::recordRun('publications:check-connections', false);

    expect(Cache::get('sched:last-run:publications:recover-stuck'))->toBe('ok')
        ->and(Cache::get('sched:last-run:publications:check-connections'))->toBe('failure');
});

it('lists upcoming publications and expiring tokens in the output', function () {
    $content = Content::factory()->create();
    Publication::factory()->create([
        'team_id' => $content->team_id,
        'content_id' => $content->id,
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->addHours(3),
    ]);

    $this->artisan('publications:health')
        ->expectsOutputToContain('Önümüzdeki 24 saatteki yayınlar')
        ->assertSuccessful();
});
