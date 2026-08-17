<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /*
    |--------------------------------------------------------------------
    | İlişkiler
    |--------------------------------------------------------------------
    */

    /**
     * Kullanıcının üyesi olduğu takımlar (tenant'lar).
     * `role` pivot kolonu, bu kullanıcının o takım içindeki
     * (owner / admin / member) rolünü tutar.
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    /**
     * Bu kullanıcının, verilen takımdaki (tenant) rolünü döner.
     * Süper admin her takımda örtük olarak "owner" yetkisine sahiptir.
     */
    public function roleInTeam(Team $team): ?string
    {
        if ($this->isSuperAdmin()) {
            return TeamMember::ROLE_OWNER;
        }

        return $this->teams()
            ->whereKey($team->getKey())
            ->first()
            ?->pivot
            ?->role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /*
    |--------------------------------------------------------------------
    | Filament: Panel erişimi
    |--------------------------------------------------------------------
    */

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            // Merkezi (central) yönetim paneli sadece süper adminlere açık.
            // Buradan TÜM hesaplar (tenant'lar) yönetilebilir.
            'admin' => $this->isSuperAdmin(),

            // Tenant paneline, en az bir takıma üye olan HERKES girebilir.
            // Süper adminler zaten getTenants() sayesinde tüm takımları görür.
            'app' => $this->isSuperAdmin() || $this->teams()->exists(),

            default => false,
        };
    }

    /*
    |--------------------------------------------------------------------
    | Filament: Multi-tenancy (HasTenants)
    |--------------------------------------------------------------------
    */

    /**
     * Bu kullanıcının erişebileceği tenant (Team) listesi.
     * Süper admin TÜM hesapları görür ve arasında geçiş yapabilir.
     */
    public function getTenants(Panel $panel): array|Collection
    {
        if ($this->isSuperAdmin()) {
            return Team::query()->orderBy('name')->get();
        }

        return $this->teams;
    }

    /**
     * URL manipülasyonuyla başka bir tenant'a erişimi engeller.
     * Süper admin her tenant'a erişebilir.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->teams()->whereKey($tenant->getKey())->exists();
    }
}
