<?php

use App\Events\PublicationPublishFailed;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function stuckPublishing(array $attributes = []): Publication
{
    return Publication::factory()->create([
        ...$attributes,
        'status' => Publication::STATUS_PUBLISHING,
        'updated_at' => now()->subHours(2),
    ]);
}

it('recovers a publication stuck in publishing for more than an hour', function () {
    Event::fake([PublicationPublishFailed::class]);

    $publication = stuckPublishing();

    $this->artisan('publications:recover-stuck')->assertSuccessful();

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_FAILED)
        ->error_message->toBe('publishing_timed_out');

    Event::assertDispatchedTimes(PublicationPublishFailed::class, 1);
});

it('does not touch a freshly claimed publishing publication', function () {
    Event::fake([PublicationPublishFailed::class]);

    // Queue retry'ı az önce claim etmiş gibi — updated_at taze.
    Publication::factory()->create([
        'status' => Publication::STATUS_PUBLISHING,
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('publications:recover-stuck')->assertSuccessful();

    expect(Publication::query()->where('status', Publication::STATUS_PUBLISHING)->count())->toBe(1);
    Event::assertNotDispatched(PublicationPublishFailed::class);
});

it('never fails a publication that already has a media_id', function () {
    Event::fake([PublicationPublishFailed::class]);

    // Yayın gerçekleşmiş (media_id var) ama finalize edilememiş — korunur.
    $publication = stuckPublishing(['media_id' => '17841400000000001']);

    $this->artisan('publications:recover-stuck')->assertSuccessful();

    expect($publication->fresh())->status->toBe(Publication::STATUS_PUBLISHING);
    Event::assertNotDispatched(PublicationPublishFailed::class);
});

it('ignores publications in non-publishing statuses', function () {
    Event::fake([PublicationPublishFailed::class]);

    Publication::factory()->published()->create(['updated_at' => now()->subHours(2)]);
    Publication::factory()->failed()->create(['updated_at' => now()->subHours(2)]);
    Publication::factory()->flagged()->create(['updated_at' => now()->subHours(2)]);
    Publication::factory()->cancelled()->create(['updated_at' => now()->subHours(2)]);
    Publication::factory()->create(['updated_at' => now()->subHours(2)]); // draft
    Publication::factory()->due()->create(['updated_at' => now()->subHours(2)]); // scheduled

    $this->artisan('publications:recover-stuck')->assertSuccessful();

    Event::assertNotDispatched(PublicationPublishFailed::class);
    expect(Publication::query()->where('status', Publication::STATUS_FAILED)->count())->toBe(1);
});

it('recovers multiple stuck publications', function () {
    Event::fake([PublicationPublishFailed::class]);

    stuckPublishing();
    stuckPublishing();
    stuckPublishing();

    $this->artisan('publications:recover-stuck')->assertSuccessful();

    expect(Publication::query()->where('status', Publication::STATUS_FAILED)->count())->toBe(3);
    Event::assertDispatchedTimes(PublicationPublishFailed::class, 3);
});
