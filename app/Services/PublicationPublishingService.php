<?php

namespace App\Services;

use App\Domain\Instagram\HasPublishableMedia;
use App\Domain\Instagram\InstagramMediaFactory;
use App\Domain\Instagram\Media\CarouselChild;
use App\Domain\Instagram\Media\CarouselMedia;
use App\Events\PublicationPublished;
use App\Models\InstagramAccount;
use App\Models\Publication;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Bir Publication kaydını Instagram'a yayınlar. Mevcut
 * PublishInstagramPostService'in idempotency desenlerinin Publication
 * üzerinden uygulanmış hâlidir (Faz A / Adım 1):
 *
 * 1. Media ID Guard — zaten yayınlanmış yayın tekrar yayınlanmaz
 * 2. Atomic Claim — scheduled/draft → publishing atomik geçiş (çift dispatch engeli)
 * 3. Container Resume — worker çökse sonra tekrar denense, container yeniden oluşturulmaz
 * 4. Cache Lock — aynı yayın için paralel worker engeli
 *
 * İçerik okumaları HasPublishableMedia sözleşmesiyle $publication->content
 * üzerinden yapılır; caption_override varsa content caption'ını ezer.
 *
 * NOT: Bu adımda event dispatch YOKTUR — mevcut Post* event'leri InstagramPost
 * tiplidir ve değiştirilmemesi istendi. Faz A4'te publication event'leri eklenecek.
 */
class PublicationPublishingService
{
    private const LOCK_TIMEOUT = 300;

    /**
     * @param  string  $trigger  Akışın tetikleyicisi: 'scheduled' (queue) veya 'manual' (publishNow)
     */
    public function publish(Publication $publication, ?string $flowId = null, string $trigger = 'scheduled'): Publication
    {
        $flowId ??= (string) Str::uuid();

        $log = new PublishFlowLogger($flowId, [
            'publication_id' => $publication->id,
            'team_id' => $publication->team_id,
            'ig_user_id' => $publication->ig_user_id,
            'trigger' => $trigger,
        ]);

        $log->log('publish.start');

        // Pattern 1: Media ID Guard — zaten yayınlanmış, atla
        if ($publication->media_id) {
            $log->log('publish.skip', ['reason' => 'already_published']);

            return $publication->fresh();
        }

        // Pattern 2: Atomic Claim
        $publication->refresh();

        if (! $publication->atomicClaim()) {
            $log->warn('publish.claim.failed', ['status' => $publication->status]);

            return $publication;
        }

        $log->log('publish.claim.ok', ['status' => $publication->status]);

        // Pattern 4: Cache Lock — paralel worker koruması
        $lock = Cache::lock("publication-publish-{$publication->id}", self::LOCK_TIMEOUT);

        if (! $lock->get()) {
            $log->warn('publish.lock.busy');

            return $publication;
        }

        $log->log('publish.lock.acquired');

        try {
            return $this->runPublishFlow($publication, $log);
        } catch (Throwable $exception) {
            // H1 deseni: Geçici hata — status 'publishing' bırakılır; kalıcı FAILED
            // job'ın failed()'ında (queue retry tükenince) set edilir.
            $log->warn('publish.error', [
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
                'retryable' => $this->isRetryable($exception),
            ]);

            $publication->forceFill([
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Manuel (senkron) yayın path'i. Kuyruk retry'ı olmadığından ilk hatada
     * yayın kalıcı olarak FAILED durumuna alınır (mevcut publishNow davranışı).
     */
    public function publishNow(Publication $publication, ?string $flowId = null): Publication
    {
        $flowId ??= (string) Str::uuid();

        $log = new PublishFlowLogger($flowId, [
            'publication_id' => $publication->id,
            'team_id' => $publication->team_id,
            'ig_user_id' => $publication->ig_user_id,
            'trigger' => 'manual',
        ]);

        $publication->forceFill(['scheduled_at' => null])->save();

        try {
            return $this->publish($publication, $flowId, 'manual');
        } catch (Throwable $exception) {
            $publication->forceFill([
                'status' => Publication::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            $log->log('publication.persist', [
                'persist' => 'failed',
                'final_status' => Publication::STATUS_FAILED,
            ]);

            throw $exception;
        }
    }

    /**
     * Kilot sonrası asıl yayın akışı: limit kontrolü, container, publish, persist.
     */
    protected function runPublishFlow(Publication $publication, PublishFlowLogger $log): Publication
    {
        $instagram = $this->resolveClient($publication);
        $log->log('publish.client.resolved');

        if (! $instagram->isWithinPublishingLimit($publication->ig_user_id)) {
            throw new RuntimeException('Instagram 24 saatlik API yayın limiti doldu.');
        }

        $log->log('publish.limit.ok');

        $source = $this->resolveMediaSource($publication);

        // Gözlem: Instagram'a gönderilecek GERÇEK medya URL(leri).
        $childUrls = $source->isCarousel()
            ? collect($source->getChildren() ?? [])
                ->filter()
                ->map(fn (mixed $child): string => CarouselChild::from($child)->url)
                ->values()
                ->all()
            : [];

        $log->log('publish.media.url', [
            'media_url' => $source->getMediaUrl(),
            'child_urls' => $childUrls,
        ]);

        $this->warnIfMediaUrlNotPublic($log, $source->getMediaUrl(), $childUrls);

        // Pattern 3: Container Resume
        $containerResumed = $publication->container_id !== null;
        $containerId = $publication->container_id ?: $this->createContainer($source, $instagram, $publication->ig_user_id, $publication);

        // container_id hemen persist edilir; retry aynı container'ı yeniden kullanır.
        if (! $containerResumed) {
            $publication->forceFill(['container_id' => $containerId])->save();
        }

        $media = InstagramMediaFactory::instance()->make($source);

        $log->log('publish.container.ready', [
            'resumed' => $containerResumed,
            'media_type' => $source->getMediaType(),
        ]);

        if ($media->isVideo()) {
            $log->log('publish.video.waiting');
            $instagram->waitForContainerToFinish($containerId);
        }

        $published = $instagram->publishMedia($publication->ig_user_id, $containerId);

        $mediaId = $published['id'] ?? null;

        $log->log('publish.media.published', ['media_id' => $mediaId]);

        $permalink = null;
        $igTimestamp = null;

        if ($mediaId) {
            $response = $instagram->getMedia(
                $mediaId,
                'id,permalink,timestamp',
            );
            $permalink = $response['permalink'] ?? null;
            $igTimestamp = $response['timestamp'] ?? null;
        }

        $publication->forceFill([
            'container_id' => $containerId,
            'carousel_child_container_ids' => null,
            'media_id' => $mediaId,
            'permalink' => $permalink,
            'ig_media_timestamp' => $igTimestamp,
            'status' => Publication::STATUS_PUBLISHED,
            'scheduled_at' => null,
            'error_message' => null,
            'published_at' => now(),
        ])->save();

        $log->log('publish.persist', [
            'persist' => 'published',
            'final_status' => Publication::STATUS_PUBLISHED,
        ]);

        // Yalnızca gerçekten yeni yayınlanan yayın için event — media_id
        // guard'ıyla atlanan tekrar denemelerde event üretilmez.
        PublicationPublished::dispatch($publication->fresh());

        return $publication->fresh();
    }

    /**
     * Payload üretiminde kullanılacak içerik kaynağını çözer. caption_override
     * varsa content caption'ını ezer.
     */
    protected function resolveMediaSource(Publication $publication): HasPublishableMedia
    {
        $content = $publication->content;

        if ($publication->caption_override === null) {
            return $content;
        }

        return new class($content, (string) $publication->caption_override) implements HasPublishableMedia
        {
            public function __construct(
                private readonly HasPublishableMedia $inner,
                private readonly string $captionOverride,
            ) {}

            public function getMediaType(): string
            {
                return $this->inner->getMediaType();
            }

            public function getMediaProductType(): ?string
            {
                return $this->inner->getMediaProductType();
            }

            public function isCarousel(): bool
            {
                return $this->inner->isCarousel();
            }

            public function isVideo(): bool
            {
                return $this->inner->isVideo();
            }

            public function getCaption(): ?string
            {
                return $this->captionOverride;
            }

            public function getAltText(): ?string
            {
                return $this->inner->getAltText();
            }

            public function getMediaUrl(): ?string
            {
                return $this->inner->getMediaUrl();
            }

            public function getStoryLink(): ?string
            {
                return $this->inner->getStoryLink();
            }

            /**
             * @return array<int, mixed>|null
             */
            public function getChildren(): ?array
            {
                return $this->inner->getChildren();
            }

            public function isAiGenerated(): bool
            {
                return $this->inner->isAiGenerated();
            }
        };
    }

    /**
     * Yayının kendi Instagram hesabını bulur ve istemciyi strictly o hesabın
     * jetonuyla kurar. Hesap/jeton yoksa açık hata fırlatır (global fallback yok).
     */
    protected function resolveClient(Publication $publication): InstagramPublishingService
    {
        $account = InstagramAccount::query()
            ->where('team_id', $publication->team_id)
            ->where('ig_user_id', $publication->ig_user_id)
            ->first();

        if (! $account) {
            throw new RuntimeException('Yayının bağlı olduğu Instagram hesabı bulunamadı; önce hesabı bağlayın.');
        }

        return InstagramPublishingService::forAccount($account);
    }

    /**
     * Medya türüne göre tekli veya karusel konteyner oluşturur ve
     * konteyner ID'sini döner.
     */
    protected function createContainer(HasPublishableMedia $source, InstagramPublishingService $instagram, string $igUserId, Publication $publication): string
    {
        $media = InstagramMediaFactory::instance()->make($source);

        // Carousel ayrı akış: önce item container'ları, sonra karusel container'ı.
        if ($media instanceof CarouselMedia) {
            return $this->createCarouselContainer($media, $instagram, $igUserId, $publication);
        }

        $container = $instagram->createMediaContainerPayload(
            $igUserId,
            $media->buildContainerPayload(),
        );

        return (string) $container['id'];
    }

    protected function createCarouselContainer(CarouselMedia $media, InstagramPublishingService $instagram, string $igUserId, Publication $publication): string
    {
        $children = $media->childUrls();
        $count = count($children);

        if ($count < 2 || $count > 10) {
            throw new RuntimeException('Karusel gönderileri 2 ile 10 medya içermelidir.');
        }

        // Carousel checkpoint (TryPost PublishCheckpoint deseninin kolon-tabanlı
        // uyarlanışı): her çocuk container oluşturulduğunda kalıcı olarak
        // kaydedilir. Queue retry'ında tamamlanan çocuklar yeniden oluşturulmaz.
        $checkpoint = array_values($publication->carousel_child_container_ids ?? []);

        $childIds = [];

        foreach ($children as $index => $child) {
            if (isset($checkpoint[$index])) {
                $childIds[] = $checkpoint[$index];

                continue;
            }

            $childId = $this->createCarouselItemContainer(
                $child,
                $instagram,
                $igUserId,
            );

            // İlerleme anında persist edilir — bir sonraki çocukta çökme olsa
            // bile bu container retry'ta yeniden kullanılır.
            $checkpoint[$index] = $childId;
            $publication->forceFill(['carousel_child_container_ids' => array_values($checkpoint)])->save();

            $childIds[] = $childId;
        }

        $carousel = $instagram->createCarouselContainer(
            $igUserId,
            $childIds,
            $media->buildContainerPayload($childIds)->toPayload(),
        );

        return (string) $carousel['id'];
    }

    protected function createCarouselItemContainer(CarouselChild $child, InstagramPublishingService $instagram, string $igUserId): string
    {
        $container = $instagram->createMediaContainerPayload(
            $igUserId,
            $child->containerPayload(),
        );

        return (string) $container['id'];
    }

    /**
     * HTTP/network hataları retry edilebilir; diğer iş kuralları edilemez.
     */
    protected function isRetryable(Throwable $exception): bool
    {
        return $exception instanceof RequestException;
    }

    /**
     * @see PublishInstagramPostService::warnIfMediaUrlNotPublic()
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
}
