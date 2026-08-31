<?php

use App\Providers\AppServiceProvider;

it('refuses to boot outside local/testing when the mcp token is missing', function () {
    config([
        'media.mcp_token' => null,
        'app.media_tenant_hash_key' => 'test-hash-key',
    ]);

    app()->detectEnvironment(fn (): string => 'production');

    try {
        (new AppServiceProvider(app()))->boot();

        $this->fail('RuntimeException beklenirdi.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('MCP_TOKEN tanımlı değil — uygulama başlatılamıyor.');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('refuses to boot outside local/testing when the media tenant hash key is missing', function () {
    config([
        'media.mcp_token' => 'secret-token',
        'app.media_tenant_hash_key' => null,
    ]);

    app()->detectEnvironment(fn (): string => 'production');

    try {
        (new AppServiceProvider(app()))->boot();

        $this->fail('RuntimeException beklenirdi.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('MEDIA_TENANT_HASH_KEY tanımlı değil — uygulama başlatılamıyor.');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('boots outside local/testing when required secrets are present', function () {
    config([
        'media.mcp_token' => 'secret-token',
        'app.media_tenant_hash_key' => 'test-hash-key',
    ]);

    app()->detectEnvironment(fn (): string => 'production');

    try {
        (new AppServiceProvider(app()))->boot();

        expect(true)->toBeTrue();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});
