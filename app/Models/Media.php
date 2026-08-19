<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends \Awcodes\Curator\Models\Media
{
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
