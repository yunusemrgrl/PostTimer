<?php

namespace App\Policies;

use App\Models\TeamMember;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Tenant panelindeki (App panel) üye yönetimini kontrol eder.
 *
 * - owner / admin  -> üye ekleyebilir, düzenleyebilir, çıkarabilir.
 * - member         -> sadece listeyi görebilir.
 *
 * Süper admin, AppServiceProvider'daki Gate::before sayesinde zaten
 * her zaman izinlidir; burada tekrar kontrol etmeye gerek yok.
 */
class TeamMemberPolicy
{
    public function viewAny(User $user): bool
    {
        $team = Filament::getTenant();

        return $team && $user->teams()->whereKey($team->getKey())->exists();
    }

    public function view(User $user, TeamMember $teamMember): bool
    {
        return $teamMember->team_id === Filament::getTenant()?->getKey();
    }

    public function create(User $user): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function update(User $user, TeamMember $teamMember): bool
    {
        return $this->isManagerOfCurrentTeam($user);
    }

    public function delete(User $user, TeamMember $teamMember): bool
    {
        // Sahibin (owner) takımdan çıkarılmasını engelle.
        if ($teamMember->role === TeamMember::ROLE_OWNER) {
            return false;
        }

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
