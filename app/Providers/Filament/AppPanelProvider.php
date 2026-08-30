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
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
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
            ])
            ->assets([])
            ->renderHook(
                PanelsRenderHook::SCRIPTS_BEFORE,
                fn (): string => '
                    <script type="module" src="'.e(Vite::asset('resources/js/video-thumbnail.js')).'"></script>
                    <script type="module" src="'.e(Vite::asset('resources/js/video-dub.js')).'"></script>
                ',
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => '<style>
                    .fi-ta-content-grid {
                        display: flex !important;
                        flex-wrap: nowrap !important;
                        overflow-x: auto;
                        scroll-snap-type: x mandatory;
                        gap: 1rem;
                        padding: 0 0 1rem 0;
                        --cols-default: auto !important;
                        --cols-md: auto !important;
                        --cols-lg: auto !important;
                        --cols-xl: auto !important;
                    }
                    .fi-ta-content-grid > [role="listitem"] {
                        flex: 0 0 220px !important;
                        scroll-snap-align: start;
                        scroll-snap-stop: always;
                    }
                </style>'
            );
    }
}
