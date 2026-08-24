<?php

declare(strict_types=1);

use App\Filament\Curator\TenantPathGenerator;
use App\Models\Media;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.media_tenant_hash_key' => 'test-secret-key']);
    Storage::fake('public');

    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);
});

/**
 * Test verisi (factory) her zaman panel boot edilmeden ÖNCE oluşturulmalı;
 * Filament'in `creating` dinleyicisi kayıtları aktif tenant'a yeniden
 * ilişkilendirir. (bkz. .ai/rules/tests.md)
 */
function bootCuratorTenantPanel(Team $team): void
{
    Filament::setCurrentPanel('app');
    Filament::setTenant($team);
    Filament::bootCurrentPanel();
}

it('generates a tenant-scoped path via the curator path generator', function () {
    bootCuratorTenantPanel($this->team);

    $hash = hash_hmac('sha256', (string) $this->team->getKey(), 'test-secret-key');
    $path = app(TenantPathGenerator::class)->getPath();

    expect($path)
        ->toStartWith("tenants/{$hash}/media/")
        ->toMatch('/^tenants\/[0-9a-f]{64}\/media\/\d{4}\/\d{2}$/');
});

it('isolates media paths between tenants through the path generator', function () {
    $teamA = $this->team;
    $teamB = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    bootCuratorTenantPanel($teamA);
    $pathA = app(TenantPathGenerator::class)->getPath();

    Filament::setTenant($teamB);
    $pathB = app(TenantPathGenerator::class)->getPath();

    $hashA = hash_hmac('sha256', (string) $teamA->getKey(), 'test-secret-key');
    $hashB = hash_hmac('sha256', (string) $teamB->getKey(), 'test-secret-key');

    expect($pathA)
        ->toStartWith("tenants/{$hashA}/media/")
        ->and($pathB)->toStartWith("tenants/{$hashB}/media/")
        ->and($pathA)->not->toBe($pathB);
});

it('throws when the path generator has no active tenant', function () {
    expect(fn () => app(TenantPathGenerator::class)->getPath())
        ->toThrow(RuntimeException::class, 'active tenant');
});

it('throws when the media tenant hash key is not configured', function () {
    bootCuratorTenantPanel($this->team);
    config(['app.media_tenant_hash_key' => null]);

    expect(fn () => app(TenantPathGenerator::class)->getPath())
        ->toThrow(RuntimeException::class, 'MEDIA_TENANT_HASH_KEY');
});

it('associates media with the correct team', function () {
    $otherTeam = Team::factory()->create();

    $ownMedia = Media::factory()->for($this->team)->create();
    $otherMedia = Media::factory()->for($otherTeam)->create();

    expect($ownMedia->team->is($this->team))->toBeTrue()
        ->and($otherMedia->team->is($otherTeam))->toBeTrue()
        ->and($ownMedia->team->is($otherTeam))->toBeFalse();

    assertDatabaseHas('curator', ['id' => $ownMedia->id, 'team_id' => $this->team->id]);
    assertDatabaseHas('curator', ['id' => $otherMedia->id, 'team_id' => $otherTeam->id]);
});

it('cascades media deletion when a team is removed', function () {
    $team = Team::factory()->create();
    $media = Media::factory()->for($team)->create();

    $team->delete();

    assertDatabaseMissing('curator', ['id' => $media->id]);
});

it('exposes the url accessor and required columns on a stored media record', function () {
    $team = Team::factory()->create();
    $path = 'tenants/'.hash_hmac('sha256', (string) $team->getKey(), 'test-secret-key').'/media/2026/08/file.png';

    // Geçerli PNG baytları — magic-byte doğrulaması kaydı silmesin diye.
    Storage::disk('public')->put($path, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89");

    $media = Media::factory()->for($team)->create([
        'disk' => 'public',
        'path' => $path,
        'name' => 'file',
        'ext' => 'png',
    ]);

    $queried = Media::query()
        ->select('id', 'name', 'disk', 'path', 'team_id')
        ->latest()
        ->first();

    expect($queried)->not->toBeNull()
        ->and($queried->team_id)->toBe($team->id)
        ->and($queried->disk)->toBe('public')
        ->and($queried->path)->toBe($path)
        ->and($queried->url)->toBe(Storage::disk('public')->url($path));
});

it('uses direct video and thumbnail routes for video media previews', function () {
    $media = Media::factory()->for($this->team)->create([
        'disk' => 'public',
        'name' => 'video-file',
        'path' => 'media/video-file.mp4',
        'ext' => 'mp4',
        'type' => 'video/mp4',
        'curations' => [
            'video_thumbnail' => 'media/video-file-thumbnail.jpg',
        ],
    ]);

    expect($media->thumbnail_url)->toBe(route('media.thumbnail', ['media' => 'video-file']))
        ->and($media->medium_url)->toBe(route('media.video', ['media' => 'video-file']))
        ->and($media->large_url)->toBe(route('media.video', ['media' => 'video-file']));
});
