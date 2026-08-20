<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Domain 4 — Takıma bağlı Telegram bot ayarları.
 * Bot token + chat ID + doğrulama durumu saklar.
 */
class TelegramSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'chat_id',
        'verification_code',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'is_verified' => 'boolean',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isConfigured(): bool
    {
        return $this->is_verified && $this->chat_id !== null;
    }
}
