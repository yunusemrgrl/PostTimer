<?php

use App\Filament\App\Pages\MediaLibrary;
use App\Models\Media;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

it('lists only the active tenant media', function () {
    $otherTeam = Team::factory()->create();

    Media::factory()->create(['team_id' => $this->team->id, 'title' => 'Bizim görsel']);

    // Curator'ın tenancy hook'u aktif tenant'ı yazar; "başka tenant"
    // kaydını events'siz oluşturarak gerçek bir yabancı kayıt simüle ederiz.
    Media::withoutEvents(fn () => Media::factory()->create([
        'team_id' => $otherTeam->id,
        'title' => 'Başkasının görseli',
    ]));

    Livewire::test(MediaLibrary::class)
        ->assertSee('Bizim görsel')
        ->assertDontSee('Başkasının görseli');
});

it('filters media by tab', function () {
    Media::factory()->create([
        'team_id' => $this->team->id,
        'title' => 'Fotoğraf kartı',
        'type' => 'image/jpeg',
        'ext' => 'jpg',
    ]);
    Media::factory()->create([
        'team_id' => $this->team->id,
        'title' => 'Video kartı',
        'type' => 'video/mp4',
        'ext' => 'mp4',
    ]);

    Livewire::test(MediaLibrary::class)
        ->set('tab', 'videos')
        ->assertSee('Video kartı')
        ->assertDontSee('Fotoğraf kartı');
});

it('searches media by title', function () {
    Media::factory()->create(['team_id' => $this->team->id, 'title' => 'Portakal kampanyası']);
    Media::factory()->create(['team_id' => $this->team->id, 'title' => 'Elma duyurusu']);

    Livewire::test(MediaLibrary::class)
        ->set('search', 'Portakal')
        ->assertSee('Portakal kampanyası')
        ->assertDontSee('Elma duyurusu');
});

it('deletes a media record from the grid', function () {
    $media = Media::factory()->create(['team_id' => $this->team->id]);

    Livewire::test(MediaLibrary::class)
        ->call('deleteMedia', $media->id)
        ->assertNotified('Medya silindi');

    expect(Media::query()->find($media->id))->toBeNull();
});

it('cannot delete another tenant media', function () {
    $other = Media::withoutEvents(fn () => Media::factory()->create([
        'team_id' => Team::factory()->create()->id,
    ]));

    // Tenant dışı kayıt findOrFail'e takılır; kayıt asla silinmemeli.
    try {
        Livewire::test(MediaLibrary::class)->call('deleteMedia', $other->id);
    } catch (ModelNotFoundException) {
        // beklenen
    }

    // Curator'ın tenant global scope'u plain find()'ı da filtreler;
    // kaydın hâlâ durduğunu scope'suz doğrularız.
    expect(Media::withoutGlobalScopes()->find($other->id))->not->toBeNull();
});
