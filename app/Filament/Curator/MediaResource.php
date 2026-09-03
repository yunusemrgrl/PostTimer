<?php

declare(strict_types=1);

namespace App\Filament\Curator;

use App\Models\Media;
use Awcodes\Curator\Resources\Media\MediaResource as CuratorMediaResource;

class MediaResource extends CuratorMediaResource
{
    protected static ?string $model = Media::class;

    protected static ?string $tenantOwnershipRelationshipName = 'team';

    /**
     * Liste görünümü karakteristik MediaLibrary sayfasına taşındı; Curator
     * resource'u yalnızca picker/create akışları için arka planda çalışır.
     */
    protected static bool $shouldRegisterNavigation = false;
}
