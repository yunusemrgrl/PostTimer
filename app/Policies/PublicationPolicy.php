<?php

namespace App\Policies;

use App\Models\Publication;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Tenant panelindeki (App panel) yayın kaydı yönetimini kontrol eder.
 * InstagramPostPolicy / ContentPolicy ile aynı yetki modeli:
 *
 * - team üyesi  -> yayınları görebilir.
 * - owner / admin -> yayın oluşturabilir, düzenleyebilir, yayımlayabilir, silebilir.
 *
 * Süper admin, AppServiceProvider'daki Gate::before sayesinde zaten
 * her zaman izinlidir; burada tekrar kontrol etmeye gerek yok.
 */
class PublicationPolicy
{
    public function viewAny(User $user): bool
    {
        $team = Filament::getTenant();

        return $team && $user->teams()->whereKey($team->getKey())->exists();
    }

    public function view(User $user, Publication $publication): bool
    {
        return $publication->team_id === Filament::getTenant()?->getKey();
    }

    public function create(User $user): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function update(User $user, Publication $publication): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function delete(User $user, Publication $publication): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    /**
     * Yayını Instagram'da yayınlama yetkisi.
     */
    public function publish(User $user, Publication $publication): bool
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
