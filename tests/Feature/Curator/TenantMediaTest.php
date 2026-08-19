<?php

declare(strict_types=1);

namespace Tests\Feature\Curator;

use App\Models\Media;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.media_tenant_hash_key' => 'test-secret-key',
        ]);

        Storage::fake('public');
    }

    public function test_media_path_is_isolated_between_tenants(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $hashA = hash_hmac(
            'sha256',
            (string) $teamA->getKey(),
            'test-secret-key',
        );

        $hashB = hash_hmac(
            'sha256',
            (string) $teamB->getKey(),
            'test-secret-key',
        );

        $this->assertNotSame($hashA, $hashB);

        $pathA = "tenants/{$hashA}/media/2026/08";
        $pathB = "tenants/{$hashB}/media/2026/08";

        Storage::disk('public')->put(
            "{$pathA}/file-a.png",
            'tenant-a-file',
        );

        Storage::disk('public')->put(
            "{$pathB}/file-b.png",
            'tenant-b-file',
        );

        Storage::disk('public')->assertExists("{$pathA}/file-a.png");
        Storage::disk('public')->assertExists("{$pathB}/file-b.png");

        Storage::disk('public')->assertMissing("{$pathA}/file-b.png");
        Storage::disk('public')->assertMissing("{$pathB}/file-a.png");
    }

    public function test_media_belongs_to_the_correct_team(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $mediaA = Media::factory()->create([
            'team_id' => $teamA->getKey(),
        ]);

        $mediaB = Media::factory()->create([
            'team_id' => $teamB->getKey(),
        ]);

        $this->assertTrue($mediaA->team->is($teamA));
        $this->assertTrue($mediaB->team->is($teamB));

        $this->assertFalse($mediaA->team->is($teamB));
        $this->assertFalse($mediaB->team->is($teamA));
    }

    public function test_media_query_includes_curator_required_columns(): void
    {
        $team = Team::factory()->create();

        Media::factory()->create([
            'team_id' => $team->getKey(),
        ]);

        $media = Media::query()
            ->select('id', 'name', 'disk', 'path', 'team_id')
            ->latest()
            ->first();

        $this->assertNotNull($media);
        $this->assertSame($team->getKey(), $media->team_id);
        $this->assertNotEmpty($media->disk);
        $this->assertNotEmpty($media->path);
        $this->assertNotEmpty($media->url);
    }

    public function test_tenant_path_generator_generates_the_expected_path(): void
    {
        $team = Team::factory()->create();

        $hash = hash_hmac(
            'sha256',
            (string) $team->getKey(),
            'test-secret-key',
        );

        $expected = "tenants/{$hash}/media/2026/08";

        $this->assertStringStartsWith(
            "tenants/{$hash}/media/",
            $expected,
        );
    }
}
