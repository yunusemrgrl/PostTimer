<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Domain 1 — Link Vault: Kullanıcının affiliate linklerini ve ürün
 * meta verilerini saklar. Domain 2'deki post formu bu kayıtları
 * seçerek post_id ↔ product_id ilişkisi kurar.
 */
class Product extends Model
{
    use HasFactory;

    public const PLATFORM_AMAZON = 'amazon';

    /**
     * @var array<int, string>
     */
    public const SUPPORTED_PLATFORMS = [
        self::PLATFORM_AMAZON,
    ];

    protected $fillable = [
        'team_id',
        'platform',
        'asin',
        'url',
        'title',
        'image_url',
        'category',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    public static function platforms(): array
    {
        return [
            self::PLATFORM_AMAZON => 'Amazon',
        ];
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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
