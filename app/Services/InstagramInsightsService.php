<?php

namespace App\Services;

use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use App\Models\InstagramPostInsight;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Instagram medya insights'larını çeker, parse eder ve tarihsel snapshot
 * olarak DB'ye kaydeder. media_product_type'a göre doğru metric listesini
 * kullanır; desteklenmeyen metric'lerde hata loglayıp devam eder.
 *
 * Insights permission gerektirir: instagram_business_manage_insights
 * (Instagram Login) veya instagram_manage_insights (Facebook Login).
 * Mevcut token'larda bu scope yoksa getMediaInsights() 403 döner —
 * bu durumda service kontrollü bir RuntimeException fırlatır.
 */
class InstagramInsightsService
{
    /**
     * Bir post için insights snapshot'ı çekip DB'ye yazar.
     * Desteklenmeyen metric'lerde hata loglar ama job'ı fail ettirmez.
     *
     * @return array<int, string> Kaydedilen metric isimleri
     */
    public function syncPostInsights(InstagramPost $post): array
    {
        if (! $post->media_id) {
            throw new RuntimeException('Post henüz yayınlanmamış (media_id yok).');
        }

        $metrics = $post->supportedInsightMetrics();

        // Carousel medya insights desteklenmiyor.
        if ($metrics === []) {
            return [];
        }

        $client = $this->resolveClient($post);

        return $this->fetchAndPersist($post, $client, $metrics);
    }

    /**
     * API'den metric'leri çekip parse eder ve snapshot olarak kaydeder.
     * Tek bir metric hatada tüm sync'i fail ettirmek yerine, başarılı
     * metric'leri kaydeder ve hataları loglar.
     *
     * @param  array<int, string>  $metrics
     * @return array<int, string> Kaydedilen metric isimleri
     */
    protected function fetchAndPersist(InstagramPost $post, InstagramPublishingService $client, array $metrics): array
    {
        try {
            $response = $client->getMediaInsights($post->media_id, $metrics);
        } catch (RequestException $e) {
            $status = $e->response?->status();

            if ($status === 403) {
                throw new RuntimeException(
                    'Instagram insights permission eksik. Token\'da instagram_business_manage_insights scope\'u olmalı. '
                    .'Mevcut token\'lar bu permission\'a sahip değil; hesabı yeniden bağlayın.',
                    0,
                    $e,
                );
            }

            // Diğer API hataları (400 = desteklenmeyen metric vb.) — logla, boş döndür.
            Log::warning('instagram.insights.api_error', [
                'post_id' => $post->id,
                'media_id' => $post->media_id,
                'status' => $status,
                'body' => $e->response?->body(),
            ]);

            return [];
        }

        $saved = [];
        $now = now();

        foreach ($response['data'] ?? [] as $metric) {
            $name = $metric['name'] ?? null;
            $period = $metric['period'] ?? null;
            $value = $this->extractMetricValue($metric);

            if ($name === null || $value === null) {
                continue;
            }

            InstagramPostInsight::create([
                'instagram_post_id' => $post->id,
                'metric' => $name,
                'period' => $period,
                'value' => $value,
                'fetched_at' => $now,
            ]);

            $saved[] = $name;
        }

        return $saved;
    }

    /**
     * Insights response'undan metric değerini çıkarır. Meta response
     * formatı: values[0].value (sayı) veya total_value.value (breakdown'lu).
     */
    protected function extractMetricValue(array $metric): ?int
    {
        // Standart format: values[0].value = int
        $values = $metric['values'] ?? [];
        if (is_array($values) && isset($values[0]['value'])) {
            $val = $values[0]['value'];

            return is_numeric($val) ? (int) $val : null;
        }

        // total_value formatı (breakdown'lu metric'ler için)
        $totalValue = $metric['total_value']['value'] ?? null;
        if (is_numeric($totalValue)) {
            return (int) $totalValue;
        }

        return null;
    }

    /**
     * Post'un hesabını bulup hesap bazlı istemciyi döndürür.
     * .ai/rules/services.md: Global/fallback token YOKTUR — her zaman forAccount().
     */
    protected function resolveClient(InstagramPost $post): InstagramPublishingService
    {
        $account = InstagramAccount::query()
            ->where('team_id', $post->team_id)
            ->where('ig_user_id', $post->ig_user_id)
            ->first();

        if (! $account) {
            throw new RuntimeException('Gönderinin bağlı olduğu Instagram hesabı bulunamadı.');
        }

        return InstagramPublishingService::forAccount($account);
    }
}
