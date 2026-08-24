<?php

namespace App\Observers;

use App\Models\Media;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

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

        // Sadece videolar için thumbnail üret.
        if (! curator()->isVideo($media->ext)) {
            return;
        }

        try {
            $this->generateVideoThumbnail($media);
        } catch (\Throwable $e) {
            $this->markThumbnailStatus($media, 'failed', $e->getMessage());

            Log::error('VIDEO THUMBNAIL OLUŞTURULAMADI', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * FFmpeg'in sistemde mevcut olup olmadığını günlük cache ile tespit eder.
     * Binary yolu config'den gelir (testlerde kolayca değiştirilebilir).
     */
    private function ffmpegAvailable(): bool
    {
        return Cache::remember('curator:ffmpeg-available', now()->addDay(), function (): bool {
            $binary = (string) config('media.ffmpeg_binary', 'ffmpeg');

            $finder = windows_os()
                ? new Process(['where', $binary])
                : new Process(['which', $binary]);

            $finder->run();

            return $finder->isSuccessful();
        });
    }

    /**
     * Thumbnail üretim durumunu curations JSON'una işler: 'ok',
     * 'ffmpeg_missing' veya 'failed'. UI, thumbnailUrl() null döndüğünde
     * nedeni buradan bilebilir.
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

        $stream = Storage::disk($media->disk)->readStream($media->path);

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

    private function generateVideoThumbnail(Media $media): void
    {
        // FFmpeg yoksa sessizce atla ama nedeni kalıcı olarak işaretle —
        // her yayın için tekrar tekrar deneme yapılmaz.
        if (! $this->ffmpegAvailable()) {
            $this->markThumbnailStatus($media, 'ffmpeg_missing');

            Log::warning('VIDEO THUMBNAIL ATLANDI: ffmpeg bulunamadı', [
                'media_id' => $media->id,
            ]);

            return;
        }

        $disk = Storage::disk($media->disk);

        // Geçici dosyalar.
        $tempVideo = tempnam(sys_get_temp_dir(), 'curator-video-').'.mp4';
        $tempThumbnail = tempnam(sys_get_temp_dir(), 'curator-thumb-').'.jpg';

        try {
            /*
             * R2'den videoyu stream ederek local geçici dosyaya indiriyoruz;
             * bütün videoyu PHP memory'sine almamak için stream kopyalama.
             */
            $stream = $disk->readStream($media->path);

            if ($stream === false) {
                throw new \RuntimeException('Video R2 üzerinden okunamadı.');
            }

            $target = fopen($tempVideo, 'wb');

            if ($target === false) {
                fclose($stream);

                throw new \RuntimeException('Geçici video dosyası oluşturulamadı.');
            }

            stream_copy_to_stream($stream, $target);

            fclose($stream);
            fclose($target);

            /*
             * FFmpeg: 1. saniyeye git, 1 frame al, JPEG olarak kaydet.
             */
            $process = new Process([
                (string) config('media.ffmpeg_binary', 'ffmpeg'),
                '-y',
                '-ss',
                '1',
                '-i',
                $tempVideo,
                '-frames:v',
                '1',
                '-q:v',
                '2',
                $tempThumbnail,
            ]);

            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()
                || ! file_exists($tempThumbnail)
                || filesize($tempThumbnail) === 0
            ) {
                throw new \RuntimeException(
                    'FFmpeg thumbnail oluşturamadı: '.$process->getErrorOutput()
                );
            }

            /*
             * Thumbnail'ı videonun yanında diske koyuyoruz ve durumu
             * curations JSON'una işliyoruz (yeni migration gerekmez).
             */
            $thumbnailPath = $media->directory
                .'/'
                .$media->name
                .'-thumbnail.jpg';

            $thumbnailStream = fopen($tempThumbnail, 'rb');

            if ($thumbnailStream === false) {
                throw new \RuntimeException('Thumbnail dosyası okunamadı.');
            }

            $disk->put(
                $thumbnailPath,
                $thumbnailStream,
                [
                    'visibility' => 'public',
                    'ContentType' => 'image/jpeg',
                ]
            );

            fclose($thumbnailStream);

            $curations = $media->curations ?? [];

            $curations['video_thumbnail'] = $thumbnailPath;
            $curations['thumbnail_status'] = 'ok';
            unset($curations['thumbnail_error']);

            $media->curations = $curations;

            // Observer tekrar created() tetiklemez.
            $media->saveQuietly();

            Log::info('VIDEO THUMBNAIL OLUŞTURULDU', [
                'media_id' => $media->id,
                'thumbnail_path' => $thumbnailPath,
            ]);
        } finally {
            if (file_exists($tempVideo)) {
                @unlink($tempVideo);
            }

            if (file_exists($tempThumbnail)) {
                @unlink($tempThumbnail);
            }
        }
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
