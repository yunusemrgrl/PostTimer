<?php

use App\Filament\App\Resources\Contents\Pages\EditContent;
use App\Filament\App\Resources\Contents\RelationManagers\PublicationsRelationManager;
use App\Jobs\PublishScheduledPublication;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Media;
use App\Models\Publication;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);
    bootPanelForDistribution($this->team);

    // Edit sayfası CuratorPicker'ı render eder; media_url gerçek bir
    // Media public URL'i olmalı ki hydrate edilebilsin.
    $media = Media::factory()->create(['team_id' => $this->team->id]);

    $this->content = Content::factory()->create([
        'team_id' => $this->team->id,
        'media_url' => Media::resolveUrl((string) $media->disk, (string) $media->path, 'public'),
    ]);
});

function bootPanelForDistribution(Team $team): void
{
    Filament::setCurrentPanel('app');
    Filament::setTenant($team);
    Filament::bootCurrentPanel();
}

function accountFor(Team $team, string $username): InstagramAccount
{
    return InstagramAccount::factory()->create([
        'team_id' => $team->id,
        'ig_user_id' => (string) random_int(90000000000000, 99999999999999),
        'username' => $username,
    ]);
}

it('shows the publications relation manager for a content', function () {
    $account = accountFor($this->team, 'hesap1');
    $publication = Publication::factory()->published()->create([
        'team_id' => $this->team->id,
        'content_id' => $this->content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
    ]);

    Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $this->content,
        'pageClass' => EditContent::class,
    ])->assertCanSeeTableRecords([$publication]);
});

it('creates a publication for a single account via the distribute action', function () {
    $account = accountFor($this->team, 'hesap1');

    Livewire::test(EditContent::class, ['record' => $this->content->id])
        ->callAction('distribute', data: [
            'account_ids' => [$account->ig_user_id],
            'scheduled_at' => null,
        ]);

    expect($this->content->publications()->count())->toBe(1);

    $publication = $this->content->publications()->first();

    expect($publication)
        ->instagram_account_id->toBe($account->id)
        ->ig_user_id->toBe($account->ig_user_id)
        ->status->toBe(Publication::STATUS_DRAFT)
        ->scheduled_at->toBeNull()
        ->created_by->toBe($this->user->id)
        // caption_override yok → content caption'ı geçerli
        ->caption_override->toBeNull()
        ->and($publication->effectiveCaption())->toBe($this->content->caption);
});

it('creates publications for multiple accounts via the distribute action', function () {
    $a1 = accountFor($this->team, 'hesap1');
    $a2 = accountFor($this->team, 'hesap2');

    Livewire::test(EditContent::class, ['record' => $this->content->id])
        ->callAction('distribute', data: [
            'account_ids' => [$a1->ig_user_id, $a2->ig_user_id],
            'scheduled_at' => null,
        ]);

    expect($this->content->publications()->count())->toBe(2);
});

it('marks publications as scheduled when a future time is given', function () {
    $account = accountFor($this->team, 'hesap1');
    $when = now()->addDay()->startOfMinute();

    Livewire::test(EditContent::class, ['record' => $this->content->id])
        ->callAction('distribute', data: [
            'account_ids' => [$account->ig_user_id],
            'scheduled_at' => $when->format('Y-m-d H:i:s'),
        ]);

    expect($this->content->publications()->first())
        ->status->toBe(Publication::STATUS_SCHEDULED)
        ->scheduled_at->not->toBeNull();
});

it('does not create duplicate publications for the same content and account', function () {
    $account = accountFor($this->team, 'hesap1');

    Publication::factory()->create([
        'team_id' => $this->team->id,
        'content_id' => $this->content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
    ]);

    Livewire::test(EditContent::class, ['record' => $this->content->id])
        ->callAction('distribute', data: [
            // Dağıtılmış hesap seçeneklerden çıkar; yine de gönderilirse atlanır
            'account_ids' => [$account->ig_user_id],
            'scheduled_at' => null,
        ]);

    expect($this->content->publications()->count())->toBe(1);
});

it('cannot distribute to an account outside the tenant', function () {
    // Filament, tenant'lı panelde YENİ modellere otomatik olarak aktif
    // tenant'ı atar; bu yüzden yabancı hesap, tenant'sızken oluşturulur.
    Filament::setTenant(null);

    $otherTeam = Team::factory()->create();
    $otherAccount = accountFor($otherTeam, 'yabanci');

    expect($otherAccount->fresh()->team_id)->toBe($otherTeam->id);

    bootPanelForDistribution($this->team);

    Livewire::test(EditContent::class, ['record' => $this->content->id])
        ->callAction('distribute', data: [
            'account_ids' => [$otherAccount->ig_user_id],
            'scheduled_at' => null,
        ]);

    // Dağıtım action'ı hesabı açıkça content.team_id ile sorgular;
    // tenant-dışı hesap için publication oluşmaz.
    expect($this->content->publications()->count())->toBe(0);
});

it('lists account username and status in the relation manager', function () {
    $account = accountFor($this->team, 'hesap1');
    $publication = Publication::factory()->failed()->create([
        'team_id' => $this->team->id,
        'content_id' => $this->content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
    ]);

    Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $this->content,
        'pageClass' => EditContent::class,
    ])->assertCanSeeTableRecords([$publication]);

    expect($publication->fresh()->status)->toBe(Publication::STATUS_FAILED);
});

it('queues a retry for a failed publication', function () {
    $account = accountFor($this->team, 'hesap1');
    $publication = Publication::factory()->failed()->create([
        'team_id' => $this->team->id,
        'content_id' => $this->content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
    ]);

    Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $this->content,
        'pageClass' => EditContent::class,
    ])->callAction(TestAction::make('retry')->table($publication));

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_SCHEDULED)
        ->error_message->toBeNull();

    Queue::assertPushed(PublishScheduledPublication::class);
});

it('cancels a scheduled publication back to draft', function () {
    $account = accountFor($this->team, 'hesap1');
    $publication = Publication::factory()->scheduled()->create([
        'team_id' => $this->team->id,
        'content_id' => $this->content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
    ]);

    Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $this->content,
        'pageClass' => EditContent::class,
    ])->callAction(TestAction::make('cancel')->table($publication));

    expect($publication->fresh())
        ->status->toBe(Publication::STATUS_DRAFT)
        ->scheduled_at->toBeNull();
});
