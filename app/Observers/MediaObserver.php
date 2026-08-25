<?php

namespace App\Observers;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MediaObserver
{
    /**
     * Uzantı → beklenen gerçek MIME (magic byte). Yalnızca Instagram'a
     * gönderilebilecek medya tipleri kapsanır; listede olmayan uzantılar
     * doğrulanmadan geçer.
     *
     * @var array<string, string>
     */
    private const EXPECTED_MIME_BY_EXT = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
    ];

    private const SNIFF_BYTES = 8192;

    public function created(Media $media): void
    {
        Log::info('APP MEDIA OBSERVER ÇALIŞTI', [
            'id' => $media->id,
            'ext' => $media->ext,
            'path' => $media->path,
        ]);

        // Magic-byte doğrulaması: dosyanın içeriği, iddia edilen tipiyle
        // uyuşmuyorsa kayıt ve dosya silinir (Content-Type header'ına
        // güvenilmez — CDN yanlış yapılandırması / spoofing).
        if ($this->rejectIfMagicBytesMismatch($media)) {
            return;
        }

        // Videolar için thumbnail'i 'pending' olarak işaretle —
        // client-side JS (canvas) galeri/listeleme görüntülendiğinde
        // thumbnail'i üretip POST /media/{id}/thumbnail endpoint'ine
        // gönderecektir. Server-side FFmpeg bağımlılığı yoktur.
        if (curator()->isVideo($media->ext)) {
            $this->markThumbnailStatus($media, 'pending');
        }
    }

    /**
     * Thumbnail üretim durumunu curations JSON'una işler: 'pending',
     * 'ok' veya 'failed'. UI, thumbnailUrl() null döndüğünde nedeni
     * buradan bilebilir.
     */
    private function markThumbnailStatus(Media $media, string $status, ?string $error = null): void
    {
        $curations = $media->curations ?? [];
        $curations['thumbnail_status'] = $status;

        if ($error !== null) {
            $curations['thumbnail_error'] = $error;
        } else {
            unset($curations['thumbnail_error']);
        }

        $media->curations = $curations;
        $media->saveQuietly();
    }

    /**
     * Dosyanın ilk baytlarından gerçek MIME'i sniffer; uzantının beklettiği
     * tiple uyuşmuyorsa dosyayı ve kaydı silip true döner. Diskte fiziksel
     * dosya yoksa (test fabrikaları, harici oluşturulan kayıtlar) kontrol
     * atlanır.
     */
    private function rejectIfMagicBytesMismatch(Media $media): bool
    {
        $expected = self::EXPECTED_MIME_BY_EXT[strtolower($media->ext ?? '')] ?? null;

        if ($expected === null) {
            return false;
        }

        try {
            $stream = Storage::disk($media->disk)->readStream($media->path);
        } catch (\Throwable $e) {
            return false;
        }

        if (! is_resource($stream)) {
            return false;
        }

        $head = (string) stream_get_contents($stream, self::SNIFF_BYTES);
        fclose($stream);

        if ($head === '') {
            return false;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);

        if ($detected === $expected) {
            return false;
        }

        Log::error('MEDYA MAGIC BYTE DOĞRULAMASI BAŞARISIZ — silindi', [
            'media_id' => $media->id,
            'ext' => $media->ext,
            'expected_mime' => $expected,
            'detected_mime' => $detected,
            'path' => $media->path,
        ]);

        Storage::disk($media->disk)->delete($media->path);
        $media->deleteQuietly();

        return true;
    }

    public function updated(Media $media): void
    {
        //
    }

    public function deleted(Media $media): void
    {
        //
    }

    public function restored(Media $media): void
    {
        //
    }

    public function forceDeleted(Media $media): void
    {
        //
    }
}
