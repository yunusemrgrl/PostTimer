<?php

namespace App\Support\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Harici API sarmalayıcıları için ortak HTTP altyapısı (Template Method).
 * Alt sınıflar yalnızca configKey()/defaultBaseUrl() sağlar; timeout,
 * retry ve hata normalizasyonu tek yerden yönetilir.
 *
 * Retry notu: geçici 429/5xx ve bağlantı hatalarında yeniden denenir.
 * Ücretli çağrılarda (TTS) belirsiz timeout durumlarında çift ücretlendirme
 * riski vardır; bu yüzden retry sayısı bilinçli olarak düşük tutulur (2).
 */
abstract class AbstractExternalApiClient
{
    /**
     * config() anahtarının kökü (örn. 'gemini', 'elevenlabs').
     */
    abstract protected function configKey(): string;

    abstract protected function defaultBaseUrl(): string;

    protected function config(string $key, mixed $default = null): mixed
    {
        return config($this->configKey().'.'.$key, $default);
    }

    /**
     * Zorunlu ayar — yoksa net mesajla patlar (sessiz boş string YOK).
     */
    protected function requireConfig(string $key, string $envName): string
    {
        $value = (string) $this->config($key, '');

        if ($value === '') {
            throw new RuntimeException(
                ucfirst($this->configKey()).' yapılandırılmamış: '.$envName.' tanımlanmalı.'
            );
        }

        return $value;
    }

    /**
     * Retry'lı HTTP client (config: {key}.retries, varsayılan 2).
     */
    protected function client(int $timeoutSeconds): PendingRequest
    {
        return Http::timeout($timeoutSeconds)
            ->retry((int) $this->config('retries', 2), 500, throw: false);
    }

    protected function url(string $endpoint): string
    {
        return rtrim((string) $this->config('base_url', $this->defaultBaseUrl()), '/').$endpoint;
    }

    /**
     * Config'deki toplam timeout değeri (saniye).
     */
    protected function timeout(): int
    {
        return (int) $this->config('timeout', 120);
    }

    /**
     * Alt sınıfların tekrar eden hata mesajı üretmesini tekilleştirir.
     */
    protected function apiError(string $action, Response $response): RuntimeException
    {
        return new RuntimeException(
            ucfirst($this->configKey()).' '.$action.' başarısız (HTTP '
            .$response->status().'): '.substr($response->body(), 0, 200)
        );
    }
}
