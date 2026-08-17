<?php

namespace App\Http\Controllers;

use App\Filament\App\Resources\InstagramAccounts\InstagramAccountResource;
use App\Models\Team;
use App\Services\InstagramAccountService;
use App\Services\InstagramOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Business Login for Instagram akışını başlatır ve tamamlayan
 * controller. Oturum (state/CSRF) gerektirdiğinden web rotalarındadır.
 */
class InstagramConnectController extends Controller
{
    public function __construct(
        protected InstagramOAuthService $oauth,
    ) {}

    /**
     * Kullanıcıyı Instagram yetkilendirme penceresine yönlendirir.
     */
    public function redirect(Request $request, Team $tenant): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->canAccessTenant($tenant), 403);

        $state = Str::random(40);

        session([
            'instagram_oauth_state' => $state,
            'instagram_oauth_team' => $tenant->getKey(),
        ]);

        return redirect()->away($this->oauth->getRedirectUrl($state));
    }

    /**
     * Instagram'dan dönen kodu token'a dönüştürür ve hesabı bağlar.
     */
    public function callback(Request $request): RedirectResponse
    {
        $team = Team::query()->findOrFail(session('instagram_oauth_team'));

        $indexUrl = InstagramAccountResource::getUrl('index', ['tenant' => $team]);

        // Kullanıcı yetkilendirmeyi reddetti.
        if ($request->query('error')) {
            return redirect()
                ->to($indexUrl)
                ->with('danger', 'Instagram bağlantısı reddedildi: '.($request->query('error_description') ?? $request->query('error')));
        }

        $state = (string) $request->query('state', '');

        if (! $state || ! hash_equals((string) session()->pull('instagram_oauth_state', ''), $state)) {
            session()->forget('instagram_oauth_team');

            return redirect()
                ->to($indexUrl)
                ->with('danger', 'Güvenlik doğrulaması başarısız (state). Lütfen tekrar deneyin.');
        }

        session()->forget('instagram_oauth_team');

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect()->to($indexUrl)->with('danger', 'Instagram yetkilendirme kodu alınamadı.');
        }

        try {
            $shortLived = $this->oauth->exchangeCodeForShortLivedToken($code);
            $longLived = $this->oauth->exchangeForLongLivedToken($shortLived['access_token']);

            $account = $team->instagramAccounts()->updateOrCreate(
                ['ig_user_id' => $shortLived['user_id']],
                [
                    'access_token' => $longLived['access_token'],
                    'api_host' => 'graph.instagram.com',
                    'token_expires_at' => $longLived['expires_in'] > 0
                        ? now()->addSeconds($longLived['expires_in'])
                        : null,
                ],
            );

            app(InstagramAccountService::class)->sync($account);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->to($indexUrl)
                ->with('danger', 'Instagram bağlantısı kurulamadı: '.$exception->getMessage());
        }

        return redirect()
            ->to($indexUrl)
            ->with('success', "Instagram hesabı bağlandı: @{$account->refresh()->username}");
    }
}
