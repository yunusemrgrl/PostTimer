<?php

namespace App\Services;

use App\Models\InstagramAccount;
use App\Models\InstagramPost;
use RuntimeException;
use Throwable;

/**
 * Bir InstagramPost kaydını Instagram'a yayınlar: medya konteyneri/leri
 * oluşturur, video işlemesinin tamamlanmasını bekler ve yayınlar. Durum
 * ve hata bilgisi her zaman kayda işlenir.
 *
 * API istemcisi her zaman gönderinin kendi hesabından (team_id +
 * ig_user_id eşleşmesi) kurulur; jeton yoksa işlem başlamaz.
 */
class PublishInstagramPostService
{
    public function publish(InstagramPost $post): InstagramPost
    {
        if ($post->status === InstagramPost::STATUS_PUBLISHED) {
            return $post;
        }

        try {
            $instagram = $this->resolveClient($post);

            if (! $instagram->isWithinPublishingLimit($post->ig_user_id)) {
                throw new RuntimeException('Instagram 24 saatlik API yayın limiti doldu.');
            }

            $containerId = $this->createContainer($post, $instagram);

            if ($post->isVideo()) {
                $instagram->waitForContainerToFinish($containerId);
            }

            $published = $instagram->publishMedia($post->ig_user_id, $containerId);

            $post->forceFill([
                'container_id' => $containerId,
                'media_id' => $published['id'] ?? null,
                'status' => InstagramPost::STATUS_PUBLISHED,
                'scheduled_at' => null,
                'error_message' => null,
                'published_at' => now(),
            ])->save();

            return $post;
        } catch (Throwable $exception) {
            $post->forceFill([
                'status' => InstagramPost::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    /**
     * Zamanlanmış bir gönderiyi planı iptal edip hemen yayınlar.
     */
    public function publishNow(InstagramPost $post): InstagramPost
    {
        $post->forceFill(['scheduled_at' => null])->save();

        return $this->publish($post);
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
