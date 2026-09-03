<?php

use App\Domain\Video\Enums\LocalizationStatus;
use App\Models\Content;
use App\Models\Publication;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\VideoLocalization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Karakteristik arayüz smoke testi. Gerçek tarayıcı (Playwright) ile:
 *  - login olur, tenant'a geçer
 *  - içerik kart grid'ini render eder (tüm publication + localization durumları)
 *  - editör (create/edit) ve canlı önizlemeyi açar
 *  - "Yerelleştirme Sonucu" ve "AI Çeviri" modallarını tetikler
 *  - medya kütüphanesini gezer
 *
 * Her adımda console (JS) hataları dinlenir; backend 500'ü visit/render
 * sırasında exception olarak yakalar (kullanıcının gördüğü
 * UnhandledMatchError gibi hatalar burada patlar).
 */
beforeEach(function () {
    config(['app.media_tenant_hash_key' => 'smoke-test-key']);

    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->slug = $this->team->slug;

    // Dış ağ isteği olmasın diye medya URL'leri data-URI'dir.
    $dataUri = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

    $this->video = Content::factory()->reels()->create([
        'team_id' => $this->team->id,
        'media_url' => $dataUri,
        'caption' => 'Dublaj test videosu',
    ]);

    VideoLocalization::query()->create([
        'team_id' => $this->team->id,
        'content_id' => $this->video->id,
        'status' => LocalizationStatus::Analyzed,
        'target_language' => 'tr',
        'translation' => [
            'segments' => [
                ['start' => 0, 'end' => 2, 'source' => 'hello', 'translation' => 'merhaba'],
            ],
        ],
    ]);

    Publication::factory()->scheduled()->create([
        'team_id' => $this->team->id,
        'content_id' => $this->video->id,
    ]);

    Content::factory()->create([
        'team_id' => $this->team->id,
        'caption' => 'Standart görsel gönderi',
        'media_url' => $dataUri,
    ]);

    Content::factory()->carousel()->create([
        'team_id' => $this->team->id,
        'caption' => 'Karusel gönderi',
        'children' => [
            ['url' => $dataUri],
            ['url' => $dataUri],
        ],
    ]);

    Content::factory()->story()->create([
        'team_id' => $this->team->id,
        'caption' => 'Hikaye gönderisi',
        'media_url' => $dataUri,
    ]);
});

it('walks the whole app UI without console or backend errors', function () {
    // 1) Login
    $page = visit('/app/login');

    $page
        ->assertNoJavaScriptErrors()
        ->fill('input[type="email"]', $this->user->email)
        ->fill('input[type="password"]', 'password')
        ->press('button[type="submit"]');

    // Login sonrası tenant bağlamına düşüldüğünü doğrula (dashboard).
    $page
        ->waitForText('Panel')
        ->assertNoJavaScriptErrors();

    // 2) İçerikler kart grid'i — tüm durum kombinasyonları render edilmeli.
    $page->navigate('/app/'.$this->slug.'/contents');

    $page
        ->assertSee('Dublaj test videosu')
        ->assertSee('Karusel gönderi')
        ->assertSee('Hikaye gönderisi')
        ->assertSee('Standart görsel gönderi')
        ->assertNoJavaScriptErrors();

    // 3) "Dublaj Durumu" paneli — aksiyonlar artık ⋯ menüsünde.
    $page
        ->click('button.fi-dropdown-trigger')
        ->waitForText('Dublaj Durumu')
        ->click('button:has-text("Dublaj Durumu"), a:has-text("Dublaj Durumu")')
        ->waitForText('Gemini Analizi')
        ->assertSee('Çeviri Hazır')
        ->assertSee('merhaba')
        ->assertNoJavaScriptErrors();

    // 4) Yeni ziyaret (modal state'ini sıfırla) → "AI Dublaj" modalı.
    $page->navigate('/app/'.$this->slug.'/contents');

    $page
        ->click('button.fi-dropdown-trigger')
        ->waitForText('AI Dublaj')
        ->click('button:has-text("AI Dublaj"), a:has-text("AI Dublaj")')
        ->waitForText('Hedef Dil')
        ->assertSee('AI Video Çevirisi Başlat')
        ->assertNoJavaScriptErrors();

    // 5) Edit sayfası — form + canlı önizleme entangle'ı.
    $page->navigate('/app/'.$this->slug.'/contents/'.$this->video->id.'/edit');

    $page
        ->assertSee('Canlı Önizleme')
        ->assertSee('Dublaj test videosu')
        ->assertPresent('div[x-data*="entangle"]')
        ->assertNoJavaScriptErrors();

    // 6) Create sayfası — editör + önizleme.
    $page->navigate('/app/'.$this->slug.'/contents/create');

    $page
        ->assertSee('Canlı Önizleme')
        ->assertNoJavaScriptErrors();

    // 7) Medya kütüphanesi — sekmeler + arama.
    $page->navigate('/app/'.$this->slug.'/media-library');

    $page
        ->assertSee('Medya Kütüphanesi')
        ->assertSee('Dosyaları buraya sürükleyin')
        ->click('button:has-text("Videolar")')
        ->fill('input[placeholder="Medya ara…"]', 'Test')
        ->assertNoJavaScriptErrors();
});
