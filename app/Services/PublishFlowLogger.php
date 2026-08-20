<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Domain 4 — Publish akışı için yapılandırılmış, correlate edilmiş gözlemci.
 *
 * Tüm publish adımları ayrı bir `publish` kanalına (storage/logs/publish-flow.log)
 * yazılır. Her satır, akış başına üretilen `flow_id`, `post_id` ve `team_id`
 * bağlamını taşır. Bu sınıf YALNIZCA gözlemlenebilirlik içindir; publish davranışını,
 * retry/H2 mantığını veya ağ isteklerini DEĞİŞTİRMEZ.
 *
 * GÜVENLİK: access_token, bot token, client secret, Authorization header, tam
 * request/response body, tam caption ve hassas query parametreleri ASLA loglanmaz.
 */
final class PublishFlowLogger
{
    /**
     * @param  array{post_id?: int, team_id?: int, ig_user_id?: string, ...}  $context
     */
    public function __construct(
        protected string $flowId,
        protected array $context = [],
    ) {
        $this->context['flow_id'] = $flowId;
    }

    public function flowId(): string
    {
        return $this->flowId;
    }

    public function log(string $event, array $extra = []): void
    {
        Log::channel('publish')->info($event, $extra + $this->context);
    }

    public function warn(string $event, array $extra = []): void
    {
        Log::channel('publish')->warning($event, $extra + $this->context);
    }

    /**
     * Tek bir Instagram API çağrısını loglar: method, endpoint, sıra sayısı,
     * elapsed ms ve HTTP status. Hassas alan içermez.
     */
    public function apiCall(
        string $method,
        string $endpoint,
        int $callCount,
        int $elapsedMs,
        int $httpStatus,
        array $extra = [],
    ): void {
        $this->log('instagram.api.call', $extra + [
            'http_method' => strtoupper($method),
            'endpoint' => $endpoint,
            'call_count' => $callCount,
            'elapsed_ms' => $elapsedMs,
            'http_status' => $httpStatus,
        ]);
    }

    /**
     * Hassas değeri (örn. chat_id) maskeler; yalnızca son N haneyi gösterir.
     */
    public static function maskSensitive(string $value, int $visible = 4): string
    {
        $length = strlen($value);

        if ($length <= $visible) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - $visible).substr($value, -$visible);
    }
}
