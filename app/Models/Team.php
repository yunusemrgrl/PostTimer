<?php

namespace App\Models;

use Filament\Models\Contracts\HasCurrentTenantLabel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Team extends Model implements HasCurrentTenantLabel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'owner_id',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Takımın üyeleri. `role` pivot kolonu, o kullanıcının bu takım
     * içindeki rolünü tutar (owner | admin | member).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Aynı team_user tablosuna, ayrı bir Eloquent modeli üzerinden
     * (App panelindeki MemberResource için) erişim.
     */
    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    /**
     * Bu hesaba (tenant) ait Instagram gönderileri. Filament'in App
     * panelindeki ownership relationship mekanizması bu ilişkiyi kullanır.
     */
    public function instagramPosts(): HasMany
    {
        return $this->hasMany(InstagramPost::class);
    }

    /**
     * Bu hesaba (tenant) bağlı Instagram profesyonel hesapları.
     */
    public function instagramAccounts(): HasMany
    {
        return $this->hasMany(InstagramAccount::class);
    }

    /**
     * Domain 1 — Link Vault: Bu hesaba bağlı affiliate ürünleri.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Domain 4 — Bu hesaba bağlı Telegram bot ayarları.
     */
    public function telegramSetting(): HasOne
    {
        return $this->hasOne(TelegramSetting::class);
    }

    public function getCurrentTenantLabel(): string
    {
        return 'Hesap';
    }
}
