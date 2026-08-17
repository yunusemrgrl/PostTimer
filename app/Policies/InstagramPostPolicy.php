<?php

namespace App\Policies;

use App\Models\InstagramPost;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Tenant panelindeki (App panel) Instagram gönderisi yönetimini kontrol eder.
 *
 * - team üyesi  -> gönderileri görebilir.
 * - owner / admin -> gönderi ekleyebilir, düzenleyebilir, yayınlayabilir, silebilir.
 * - member        -> sadece listeyi görebilir.
 *
 * Süper admin, AppServiceProvider'daki Gate::before sayesinde zaten
 * her zaman izinlidir; burada tekrar kontrol etmeye gerek yok.
 */
class InstagramPostPolicy
{
    public function viewAny(User $user): bool
    {
        $team = Filament::getTenant();

        return $team && $user->teams()->whereKey($team->getKey())->exists();
    }

    public function view(User $user, InstagramPost $instagramPost): bool
    {
        return $instagramPost->team_id === Filament::getTenant()?->getKey();
    }

    public function create(User $user): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function update(User $user, InstagramPost $instagramPost): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function delete(User $user, InstagramPost $instagramPost): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    /**
     * Gönderiyi Instagram'da yayınlama yetkisi.
     */
    public function publish(User $user, InstagramPost $instagramPost): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    protected function isManagerOfCurrentTeam(User $user): bool
    {
        $team = Filament::getTenant();

        if (! $team) {
            return false;
        }

        $role = $user->roleInTeam($team);

        return in_array($role, [TeamMember::ROLE_OWNER, TeamMember::ROLE_ADMIN], true);
    }
}
