<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Team kaynağı sadece Admin panelinde (merkezi panel) kullanılır.
 * AppServiceProvider'daki Gate::before zaten super_admin'i otomatik
 * geçiriyor; bu policy, ileride "support" gibi kısmi yetkili bir
 * platform rolü eklemek istersen genişletebileceğin bir iskelet sağlar.
 */
class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Team $team): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Team $team): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Team $team): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
