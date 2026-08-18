<?php

namespace App\Events;

use App\Models\InstagramPost;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class PostPublished
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public InstagramPost $post,
    ) {}
}
