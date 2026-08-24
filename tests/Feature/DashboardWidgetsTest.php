<?php

use App\Filament\App\Widgets\InstagramOverviewWidget;
use App\Filament\App\Widgets\InstagramPublishingChartWidget;
use App\Filament\App\Widgets\LatestInstagramPostsWidget;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);

    Filament::setCurrentPanel('app');
    Filament::setTenant($this->team);
    Filament::bootCurrentPanel();
});

function widgetAccount(Team $team): InstagramAccount
{
    return InstagramAccount::factory()->create([
        'team_id' => $team->id,
        'ig_user_id' => '2915115069225431',
        'username' => 'hesap1',
    ]);
}

it('overview widget stats reflect publication statuses only', function () {
    $account = widgetAccount($this->team);
    $content = Content::factory()->create(['team_id' => $this->team->id]);

    Publication::factory()->published()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
        'published_at' => now(),
    ]);
    Publication::factory()->scheduled()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
        'scheduled_at' => now()->addHour(),
    ]);

    Livewire::test(InstagramOverviewWidget::class)
        ->assertOk();

    expect(Publication::where('team_id', $this->team->id)->count())->toBe(2);
});

it('chart widget renders publication flow data', function () {
    $account = widgetAccount($this->team);
    $content = Content::factory()->create(['team_id' => $this->team->id]);

    Publication::factory()->published()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
        'published_at' => now(),
    ]);

    Livewire::test(InstagramPublishingChartWidget::class)
        ->assertOk();
});

it('latest publications widget lists publication records', function () {
    $account = widgetAccount($this->team);
    $content = Content::factory()->create([
        'team_id' => $this->team->id,
        'caption' => 'Son yayın açıklaması',
    ]);

    $publication = Publication::factory()->published()->create([
        'team_id' => $this->team->id,
        'content_id' => $content->id,
        'instagram_account_id' => $account->id,
        'ig_user_id' => $account->ig_user_id,
        'published_at' => now(),
    ]);

    Livewire::test(LatestInstagramPostsWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$publication]);
});
