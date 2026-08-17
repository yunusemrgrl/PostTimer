<?php

namespace App\Policies;

use App\Models\InstagramAccount;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Tenant panelindeki (App panel) Instagram hesap yönetimini kontrol eder.
 *
 * - team üyesi   -> hesapları görebilir.
 * - owner / admin -> hesap ekleyebilir, senkronize edebilir, düzenleyebilir.
 *
 * Süper admin, AppServiceProvider'daki Gate::before sayesinde zaten
 * her zaman izinlidir; burada tekrar kontrol etmeye gerek yok.
 */
class InstagramAccountPolicy
{
    public function viewAny(User $user): bool
    {
        $team = Filament::getTenant();

        return $team && $user->teams()->whereKey($team->getKey())->exists();
    }

    public function view(User $user, InstagramAccount $instagramAccount): bool
    {
        return $instagramAccount->team_id === Filament::getTenant()?->getKey();
    }

    public function create(User $user): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function update(User $user, InstagramAccount $instagramAccount): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function delete(User $user, InstagramAccount $instagramAccount): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    /**
     * Profil bilgilerini Instagram API'den senkronize etme yetkisi.
     */
    public function sync(User $user, InstagramAccount $instagramAccount): bool
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
