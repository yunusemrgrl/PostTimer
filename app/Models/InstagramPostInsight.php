<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bir InstagramPost'un belirli bir fetched_at anındaki insight
 * snapshot'ı. Aynı post + metric için farklı zamanlarda farklı değerler
 * saklanabilir (trend analizi için).
 */
class InstagramPostInsight extends Model
{
    protected $table = 'instagram_post_insights';

    protected $fillable = [
        'instagram_post_id',
        'publication_id',
        'metric',
        'period',
        'value',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InstagramPost, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(InstagramPost::class);
    }

    /**
     * Publication-side insights (Faz B1): publication_id doluysa snapshot
     * yeni publish domain'inden gelmiştir.
     *
     * @return BelongsTo<Publication, $this>
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
