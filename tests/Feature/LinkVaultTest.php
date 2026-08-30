<?php

use App\Domain\Stock\Services\AmazonProductParser;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->team = Team::factory()
        ->hasAttached($this->user, ['role' => TeamMember::ROLE_OWNER], 'users')
        ->create();

    $this->actingAs($this->user);
});

function bootPanelFor(Team $team): void
{
    Filament::setCurrentPanel('app');
    Filament::setTenant($team);
    Filament::bootCurrentPanel();
}

it('extracts asin from various amazon url formats', function () {
    $parser = new AmazonProductParser;

    expect($parser->extractAsin('https://www.amazon.com.tr/dp/B0CXKJ5F2N'))->toBe('B0CXKJ5F2N')
        ->and($parser->extractAsin('https://www.amazon.com.tr/Some-Product-Name/dp/B0CXKJ5F2N?tag=aff-21'))->toBe('B0CXKJ5F2N')
        ->and($parser->extractAsin('https://amzn.to/B0CXKJ5F2N'))->toBe('B0CXKJ5F2N')
        ->and($parser->extractAsin('https://example.com/not-amazon'))->toBeNull();
});

it('fetches product title and image from open graph meta', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response(
            '<html><head>'
            .'<meta property="og:title" content="Wireless Bluetooth Headphones" />'
            .'<meta property="og:image" content="https://images.amazon.com/images/I/61abc.jpg" />'
            .'</head><body></body></html>'
        ),
        '*' => Http::response(),
    ]);

    $parser = new AmazonProductParser;

    $result = $parser->parse('https://www.amazon.com.tr/dp/B0CXKJ5F2N');

    expect($result)
        ->asin->toBe('B0CXKJ5F2N')
        ->title->toBe('Wireless Bluetooth Headphones')
        ->image_url->toBe('https://images.amazon.com/images/I/61abc.jpg');
});

it('returns nulls gracefully when amazon blocks the request', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::failedConnection(),
        '*' => Http::response(),
    ]);

    $parser = new AmazonProductParser;

    $result = $parser->parse('https://www.amazon.com.tr/dp/B0CXKJ5F2N');

    expect($result)
        ->asin->toBe('B0CXKJ5F2N')
        ->title->toBeNull()
        ->image_url->toBeNull();
});

it('lists only the current tenants products', function () {
    $otherTeam = Team::factory()->create();

    $ownProduct = Product::factory()->for($this->team)->create();
    $otherProduct = Product::factory()->for($otherTeam)->create();

    bootPanelFor($this->team);

    $visibleIds = Livewire::test(ListProducts::class)
        ->instance()
        ->getTableRecords()
        ->pluck('id');

    expect($visibleIds)
        ->toContain($ownProduct->id)
        ->not->toContain($otherProduct->id);
});

it('auto-fills product info when url is entered', function () {
    Http::fake([
        'amazon.com.tr/*' => Http::response(
            '<html><head>'
            .'<meta property="og:title" content="USB-C Charger 65W" />'
            .'<meta property="og:image" content="https://images.amazon.com/images/I/charger.jpg" />'
            .'</head></html>'
        ),
        '*' => Http::response(),
    ]);

    bootPanelFor($this->team);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'url' => 'https://www.amazon.com.tr/dp/B0CXKJ5F2N',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    assertDatabaseHas('products', [
        'team_id' => $this->team->id,
        'asin' => 'B0CXKJ5F2N',
        'title' => 'USB-C Charger 65W',
        'image_url' => 'https://images.amazon.com/images/I/charger.jpg',
    ]);
});
