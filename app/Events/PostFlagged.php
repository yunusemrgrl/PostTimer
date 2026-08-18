<?php

namespace App\Events;

use App\Models\InstagramPost;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class PostFlagged
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public InstagramPost $post,
        public string $reason,
    ) {}
}
