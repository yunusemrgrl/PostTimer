<?php

namespace App\Services;

use App\Events\PostPublished;
use App\Events\PostPublishFailed;
use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Bir InstagramPost kaydını Instagram'a yayınlar. Tüm idempotency
 * önlemleri burada uygulanır:
 *
 * 1. Media ID Guard — zaten yayınlanmış post tekrar yayınlanmaz
 * 2. Atomic Claim — scheduled/draft → publishing atomik geçiş (çift dispatch engeli)
 * 3. Container Resume — worker çökse sonra tekrar denense, container yeniden oluşturulmaz
 * 4. Cache Lock — aynı post için paralel worker engeli
 * 5. Event Dispatch — yayın sonrası aksiyonlar (ilk yorum, Telegram) event ile tetiklenir
 */
class PublishInstagramPostService
{
    private const LOCK_TIMEOUT = 300;

    /**
     * @param  string  $trigger  Akışın tetikleyicisi: 'scheduled' (queue) veya 'manual' (publishNow)
     */
    public function publish(InstagramPost $post, ?string $flowId = null, string $trigger = 'scheduled'): InstagramPost
    {
        $flowId ??= (string) Str::uuid();

        // Gözlem: akışın tüm aşamaları flow_id ile correlate edilerek 'publish'
        // kanalına yazılır. Bu loglar YALNIZCA gözlem içindir; publish
        // davranışını, retry mantığını veya ağ isteklerini DEĞİŞTİRMEZ.
        $log = new PublishFlowLogger($flowId, [
            'post_id' => $post->id,
            'team_id' => $post->team_id,
            'ig_user_id' => $post->ig_user_id,
        ]);

        $log->log('publish.start', ['trigger' => $trigger]);

        // Pattern 1: Media ID Guard — zaten yayınlanmış, atla
        if ($post->media_id) {
            $log->log('publish.skip', ['reason' => 'already_published']);
            $log->log('event.post_published', ['via' => 'media_id_guard']);

            PostPublished::dispatch($post->fresh());

            return $post->fresh();
        }

        // Pattern 2: Atomic Claim — postu atomik olarak "publishing" durumuna al
        // Başka bir worker aynı postu almışsa 0 döner → çift yayın engellenir
        $post->refresh();
        if (! $post->atomicClaim()) {
            $log->warn('publish.claim.failed', ['status' => $post->status]);

            return $post;
        }

        $log->log('publish.claim.ok', ['status' => $post->status]);

        // Pattern 4: Cache Lock — paralel worker koruması
        $lock = Cache::lock("instagram-publish-{$post->id}", self::LOCK_TIMEOUT);

        if (! $lock->get()) {
            $log->warn('publish.lock.busy');

            return $post;
        }

        $log->log('publish.lock.acquired');

        try {
            $instagram = $this->resolveClient($post);
            $log->log('publish.client.resolved');

            if (! $instagram->isWithinPublishingLimit($post->ig_user_id)) {
                throw new RuntimeException('Instagram 24 saatlik API yayın limiti doldu.');
            }

            $log->log('publish.limit.ok');

            // Gözlem: Instagram'a gönderilecek GERÇEK medya URL(leri).
            // "Invalid media url" hatalarında hangi URL'nin iletildiğini
            // görmek için container oluşturulmadan ÖNCE loglanır.
            $childUrls = $post->media_type === InstagramPost::MEDIA_TYPE_CAROUSEL
                ? collect($post->children ?? [])
                    ->filter()
                    ->map(fn (mixed $child): string => $this->childUrl($child))
                    ->values()
                    ->all()
                : [];

            $log->log('publish.media.url', [
                'media_url' => $post->media_url,
                'child_urls' => $childUrls,
            ]);

            $this->warnIfMediaUrlNotPublic($log, $post->media_url, $childUrls);

            // Pattern 3: Container Resume — container zaten varsa yeniden oluşturma
            $containerResumed = $post->container_id !== null;
            $containerId = $post->container_id ?: $this->createContainer($post, $instagram);

            // container_id'yi HEMEN persist ediyoruz. waitForContainerToFinish()
            // sırasında worker ölürse (deploy, OOM, Flex interrupt), retry
            // bu container_id'yi bulup yeniden kullanır; yeni container
            // oluşturmaz (duplicate container / rate limit israfı önlenir).
            if (! $containerResumed) {
                $post->forceFill(['container_id' => $containerId])->save();
            }

            $log->log('publish.container.ready', [
                'resumed' => $containerResumed,
                'media_type' => $post->media_type,
            ]);

            if ($post->isVideo()) {
                $log->log('publish.video.waiting');
                $instagram->waitForContainerToFinish($containerId);
            }

            $published = $instagram->publishMedia($post->ig_user_id, $containerId);
            $log->log('publish.media.published', ['media_id' => $published['id'] ?? null]);

            $post->forceFill([
                'container_id' => $containerId,
                'media_id' => $published['id'] ?? null,
                'status' => InstagramPost::STATUS_PUBLISHED,
                'scheduled_at' => null,
                'error_message' => null,
                'published_at' => now(),
            ])->save();

            $log->log('publish.persist', [
                'persist' => 'published',
                'final_status' => InstagramPost::STATUS_PUBLISHED,
            ]);

            // Pattern 5: Event Dispatch — ilk yorum ve Telegram uyarısı event ile tetiklenir
            PostPublished::dispatch($post->fresh());
            $log->log('event.post_published');

            return $post->fresh();
        } catch (Throwable $exception) {
            // H1: Geçici hata — status'u 'publishing' bırakırız; yalnızca hatayı
            // kaydederiz. Queue retry'ı atomicClaim(publishing) ile aynı postu
            // yeniden alıp tekrar deneyebilir. Kalıcı FAILED + PostPublishFailed
            // event'i, retry'lar tükenince job'ın failed() metodunda fırlatılır
            // (çift event'i ve "retry çalışmıyor" sorununu önler).
            $log->warn('publish.error', [
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'retryable' => $this->isRetryable($exception),
            ]);

            $post->forceFill([
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Zamanlanmış bir gönderiyi planı iptal edip hemen yayınlar.
     *
     * Manuel (senkron) yayın path'idir; kuyruk retry'ı olmadığından ilk
     * hatada post kalıcı olarak FAILED durumuna alınır ve PostPublishFailed
     * event'i fırlatılır (mevcut davranış korunur).
     */
    public function publishNow(InstagramPost $post, ?string $flowId = null): InstagramPost
    {
        $flowId ??= (string) Str::uuid();

        $log = new PublishFlowLogger($flowId, [
            'post_id' => $post->id,
            'team_id' => $post->team_id,
            'ig_user_id' => $post->ig_user_id,
        ]);

        $post->forceFill(['scheduled_at' => null])->save();

        try {
            return $this->publish($post, $flowId, 'manual');
        } catch (Throwable $exception) {
            $post->forceFill([
                'status' => InstagramPost::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            $log->log('post.persist', [
                'persist' => 'failed',
                'final_status' => InstagramPost::STATUS_FAILED,
            ]);
            $log->log('event.post_publish_failed', ['error' => $exception->getMessage()]);

            PostPublishFailed::dispatch($post->fresh(), $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * hrtime() başlangıç değerinden şimdiye kadar geçen süreyi ms cinsinden döner.
     */
    protected function elapsedMs(int $startHrtime): int
    {
        return (int) round((hrtime(true) - $startHrtime) / 1e6);
    }

    /**
     * Bir istisna kuyruğun retry'ına (geçici/tekrar denenebilir) işaret ediyor mu?
     * HTTP/network hataları (RequestException ve alt sınıfı ConnectionException)
     * retry edilebilir; diğer iş kuralları (kota/uyarı/hesap yok) edilemez.
     */
    protected function isRetryable(Throwable $exception): bool
    {
        return $exception instanceof RequestException;
    }

    /**
     * Instagram Graph API, medyanın public HTTPS bir URL'den erişilebilir
     * olmasını bekler. Şu URL'ler yayında "Invalid media url" hatasına yol
     * açar ve uyarı olarak loglanır:
     * - http şeması veya host içermeyen URL'ler
     * - local/test hostları (localhost, *.test, *.localhost)
     * - R2/S3 API endpoint hostları (*.r2.cloudflarestorage.com,
     *   *.amazonaws.com) — bunlar public içerik host'u değildir; public
     *   bucket/CDN alan adı (disk config'indeki `url` / R2_URL) gerekir.
     * YALNIZCA gözlemdir, publish davranışını değiştirmez.
     *
     * @param  array<int, string>  $childUrls
     */
    protected function warnIfMediaUrlNotPublic(PublishFlowLogger $log, ?string $mediaUrl, array $childUrls): void
    {
        $urls = array_values(array_filter(
            [$mediaUrl, ...$childUrls],
            fn (?string $url): bool => is_string($url) && $url !== '',
        ));

        foreach ($urls as $url) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            $lowerHost = is_string($host) ? strtolower($host) : '';

            $isLocalHost = $lowerHost !== '' && (
                in_array($lowerHost, ['localhost', '127.0.0.1', '0.0.0.0'], true)
                || str_ends_with($lowerHost, '.test')
                || str_ends_with($lowerHost, '.localhost')
            );

            $isStorageApiHost = $lowerHost !== '' && (
                str_ends_with($lowerHost, '.r2.cloudflarestorage.com')
                || str_ends_with($lowerHost, '.amazonaws.com')
            );

            if ($scheme !== 'https' || $lowerHost === '' || $isLocalHost || $isStorageApiHost) {
                $log->warn('publish.media.url.not_public', [
                    'media_url' => $url,
                    'url_scheme' => $scheme,
                    'url_host' => $host,
                    'reason' => match (true) {
                        $scheme !== 'https' => 'not_https',
                        $lowerHost === '' => 'missing_host',
                        $isLocalHost => 'local_host',
                        default => 'storage_api_host',
                    },
                ]);
            }
        }
    }

    /**
     * Zamanlanmış bir gönderinin planını iptal eder ve taslağa dönerir.
     */
    public function unschedule(InstagramPost $post): InstagramPost
    {
        $post->forceFill([
            'status' => InstagramPost::STATUS_DRAFT,
            'scheduled_at' => null,
            'error_message' => null,
        ])->save();

        return $post;
    }

    /**
     * Gönderinin kendi Instagram hesabını bulur ve istemciyi strictly
     * o hesabın jetonuyla kurar. Hesap/jeton yoksa açık hata fırlatır.
     */
    protected function resolveClient(InstagramPost $post): InstagramPublishingService
    {
        $account = InstagramAccount::query()
            ->where('team_id', $post->team_id)
            ->where('ig_user_id', $post->ig_user_id)
            ->first();

        if (! $account) {
            throw new RuntimeException('Gönderinin bağlı olduğu Instagram hesabı bulunamadı; önce hesabı bağlayın.');
        }

        return InstagramPublishingService::forAccount($account);
    }

    /**
     * Medya türüne göre tekli veya karusel konteyner oluşturur ve
     * konteyner ID'sini döner.
     */
    protected function createContainer(InstagramPost $post, InstagramPublishingService $instagram): string
    {
        if ($post->media_type === InstagramPost::MEDIA_TYPE_CAROUSEL) {
            return $this->createCarouselContainer($post, $instagram);
        }

        $container = $instagram->createMediaContainer($post->ig_user_id, array_filter([
            'caption' => $post->caption,
            'is_ai_generated' => $post->is_ai_generated ?: null,
            'alt_text' => $post->alt_text !== null ? ['text' => $post->alt_text] : null,
            'media_type' => $post->media_type,
            'story_link' => $post->isStory() ? $post->story_link : null,
            $post->isVideo() ? 'video_url' : 'image_url' => $post->media_url,
        ], fn ($value) => $value !== null));

        return (string) $container['id'];
    }

    protected function createCarouselContainer(InstagramPost $post, InstagramPublishingService $instagram): string
    {
        $children = collect($post->children ?? [])->filter()->values();
        $count = $children->count();

        if ($count < 2 || $count > 10) {
            throw new RuntimeException('Karusel gönderileri 2 ile 10 medya içermelidir.');
        }

        $childIds = $children
            ->map(fn ($child): string => $this->createCarouselItemContainer(
                $post->ig_user_id,
                $this->childUrl($child),
                $instagram,
            ))
            ->all();

        $carousel = $instagram->createCarouselContainer(
            $post->ig_user_id,
            $childIds,
            array_filter([
                'caption' => $post->caption,
                'is_ai_generated' => $post->is_ai_generated ?: null,
            ], fn ($value) => $value !== null),
        );

        return (string) $carousel['id'];
    }

    /**
     * Karusel medyaları formda `['url' => ...]` dizileri olarak tutulur;
     * düz string URL'ler de desteklenir.
     */
    protected function childUrl(mixed $child): string
    {
        if (is_array($child)) {
            return (string) ($child['url'] ?? '');
        }

        return (string) $child;
    }

    protected function createCarouselItemContainer(string $igUserId, string $url, InstagramPublishingService $instagram): string
    {
        $isVideo = preg_match('/\.(mp4|mov)(\?|$)/i', $url) === 1;

        $container = $instagram->createMediaContainer($igUserId, [
            $isVideo ? 'video_url' : 'image_url' => $url,
            'is_carousel_item' => true,
        ]);

        return (string) $container['id'];
    }
}
