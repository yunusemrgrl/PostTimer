<?php

namespace App\Providers\Filament;

use App\Filament\App\Resources\Members\MemberResource;
use App\Models\Team;
use Awcodes\Curator\CuratorPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    /**
     * Tenant (hesap) paneli. Her kullanıcı burada, aktif olarak
     * seçtiği Team (tenant) bağlamında çalışır. Kaynaklar otomatik
     * olarak o tenant'a göre filtrelenir.
     *
     * Süper adminler, getTenants() sayesinde buradaki tenant
     * değiştiriciden (tenant switcher) İSTEDİĞİ HERHANGİ BİR hesaba
     * geçiş yapabilir.
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->tenantMenu(true)
            ->navigationGroups([
                'Instagram',
                'Ekip',
            ])
            ->resources([
                MemberResource::class,
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                CuratorPlugin::make(),
            ]);
    }
}
