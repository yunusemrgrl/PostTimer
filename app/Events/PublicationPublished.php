<?php

namespace App\Events;

use App\Models\Publication;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;

class PublicationPublished
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public Publication $publication,
    ) {}
}
