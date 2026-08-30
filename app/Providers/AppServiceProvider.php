<?php

namespace App\Providers;

use App\Domain\Video\Services\Contracts\TextToSpeechProvider;
use App\Domain\Video\Services\Contracts\VideoTranslationProvider;
use App\Domain\Video\Services\ElevenLabsTtsService;
use App\Domain\Video\Services\GeminiVideoTranslationService;
use Awcodes\Curator\Facades\Curator;
use Awcodes\Curator\Facades\Glide;
use Awcodes\Curator\Glide\SymfonyResponseFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // AI sağlayıcıları (Strategy): orkestratör kontratlara bağlıdır;
        // provider değişimi yalnızca buradaki binding'i değiştirir.
        $this->app->bind(VideoTranslationProvider::class, GeminiVideoTranslationService::class);
        $this->app->bind(TextToSpeechProvider::class, ElevenLabsTtsService::class);

        Glide::serverConfig([
            'response' => new SymfonyResponseFactory(
                app('request')
            ),
            'source' => Storage::disk('r2')->getDriver(),
            'source_path_prefix' => '',
            'cache' => Storage::disk('local')->getDriver(),
            'cache_path_prefix' => '.cache',
            'max_image_size' => 2000 * 2000,
            'base_url' => 'curator',
        ]);
    }

    public function boot(): void
    {
        /**
         * ÖNEMLİ: super_admin rolüne sahip kullanıcılar, uygulamadaki
         * TÜM Gate/Policy kontrollerini otomatik olarak geçer.
         *
         * Bu satır, "yönetici bütün hesaplara erişip yönetebilsin"
         * isteğinin temelini oluşturur: TeamPolicy, TeamMemberPolicy
         * veya ileride eklenecek başka policy'ler ayrı ayrı süper admin
         * kontrolü yazmak zorunda kalmaz.
         */
        Gate::before(function ($user) {
            return $user->isSuperAdmin() ? true : null;
        });

        // Sadece 'production' değil; local/testing DIŞINDAKİ her ortamda
        // (staging, qa, preview, vs.) config eksikliğini erken yakalıyoruz.
        if (! app()->environment(['local', 'testing']) && blank(config('app.media_tenant_hash_key'))) {
            throw new RuntimeException('MEDIA_TENANT_HASH_KEY tanımlı değil — uygulama başlatılamıyor.');
        }

        // Media observer: app/Models/Media.php uzerindeki #[ObservedBy] attribute'u ile bind ediliyor.
        Curator::maxSize(102400);
    }
}
