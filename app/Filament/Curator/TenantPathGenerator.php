<?php

declare(strict_types=1);

namespace App\Filament\Curator;

use Awcodes\Curator\PathGenerators\Contracts\PathGenerator;
use Filament\Facades\Filament;
use RuntimeException;

class TenantPathGenerator implements PathGenerator
{
    public function getPath(?string $baseDir = null): string
    {
        $tenant = Filament::getTenant();

        if (! $tenant) {
            throw new RuntimeException(
                'Curator media path requires an active tenant.'
            );
        }

        $secretKey = config('app.media_tenant_hash_key');

        if (! is_string($secretKey) || $secretKey === '') {
            throw new RuntimeException(
                'MEDIA_TENANT_HASH_KEY is not configured.'
            );
        }

        $tenantHash = hash_hmac(
            'sha256',
            (string) $tenant->getKey(),
            $secretKey,
        );

        $baseDir = trim($baseDir ?? 'media', '/');

        return "tenants/{$tenantHash}/{$baseDir}/".now()->format('Y/m');
    }
}
