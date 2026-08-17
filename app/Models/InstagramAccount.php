<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir takıma (tenant) bağlı Instagram profesyonel hesabı. Profil
 * bilgileri Instagram Graph API'den senkronize edilir; erişim jetonu
 * takıma özel saklanır (şifreli), boşsa genel uygulama jetonu kullanılır.
 */
class InstagramAccount extends Model
{
    use HasFactory;

    public const TYPE_BUSINESS = 'BUSINESS';

    public const TYPE_MEDIA_CREATOR = 'MEDIA_CREATOR';

    protected $fillable = [
        'team_id',
        'ig_user_id',
        'access_token',
        'api_host',
        'token_expires_at',
        'username',
        'name',
        'account_type',
        'biography',
        'website',
        'followers_count',
        'media_count',
        'profile_picture_url',
        'last_synced_at',
    ];

    /**
     * @return array<string, string>
     */
    public static function accountTypes(): array
    {
        return [
            self::TYPE_BUSINESS => 'İşletme',
            self::TYPE_MEDIA_CREATOR => 'İçerik Üretici',
        ];
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'followers_count' => 'integer',
            'media_count' => 'integer',
            'last_synced_at' => 'datetime',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * Filament'in App panelindeki ->tenant() kapsamı (automatic scoping)
     * bu ilişki üzerinden çalışır.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
