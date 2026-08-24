<?php

namespace App\Policies;

use App\Models\Content;
use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Tenant panelindeki (App panel) içerik yönetimini kontrol eder.
 * InstagramPostPolicy ile aynı yetki modeli:
 *
 * - team üyesi  -> içerikleri görebilir.
 * - owner / admin -> içerik ekleyebilir, düzenleyebilir, silebilir.
 * - member        -> sadece listeyi görebilir.
 *
 * Süper admin, AppServiceProvider'daki Gate::before sayesinde zaten
 * her zaman izinlidir; burada tekrar kontrol etmeye gerek yok.
 */
class ContentPolicy
{
    public function viewAny(User $user): bool
    {
        $team = Filament::getTenant();

        return $team && $user->teams()->whereKey($team->getKey())->exists();
    }

    public function view(User $user, Content $content): bool
    {
        return $content->team_id === Filament::getTenant()?->getKey();
    }

    public function create(User $user): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function update(User $user, Content $content): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function delete(User $user, Content $content): bool
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
