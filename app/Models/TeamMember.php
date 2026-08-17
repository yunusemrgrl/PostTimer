<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * team_user pivot tablosunu, Filament'in App (tenant) panelinde
 * ->tenant() ile otomatik olarak kapsanabilecek (scoped) bağımsız bir
 * kaynak (resource) gibi kullanabilmek için ayrı bir model olarak tanımlıyoruz.
 *
 * User::teams() ilişkisiyle AYNI tabloyu (team_user) kullanır; sadece
 * Filament'in "ownershipRelationship" mantığına uygun bir belongsTo(Team)
 * sağlamak için var.
 */
class TeamMember extends Model
{
    protected $table = 'team_user';

    protected $fillable = [
        'team_id',
        'user_id',
        'role',
    ];

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    public static function roles(): array
    {
        return [
            self::ROLE_OWNER => 'Sahip',
            self::ROLE_ADMIN => 'Yönetici',
            self::ROLE_MEMBER => 'Üye',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
