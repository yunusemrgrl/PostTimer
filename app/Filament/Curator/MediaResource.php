<?php

declare(strict_types=1);

namespace App\Filament\Curator;

use App\Models\Media;
use Awcodes\Curator\Resources\Media\MediaResource as CuratorMediaResource;

class MediaResource extends CuratorMediaResource
{
    protected static ?string $model = Media::class;

    protected static ?string $tenantOwnershipRelationshipName = 'team';
}
