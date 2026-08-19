<?php

namespace App\Observers;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class MediaObserver
{
    public function created(Media $media): void
    {
        Log::info('APP MEDIA OBSERVER ÇALIŞTI', [
            'id' => $media->id,
            'ext' => $media->ext,
            'path' => $media->path,
        ]);

        // Sadece videolar için thumbnail üret.
        if (! curator()->isVideo($media->ext)) {
            return;
        }

        try {
            $this->generateVideoThumbnail($media);
        } catch (\Throwable $e) {
            Log::error('VIDEO THUMBNAIL OLUŞTURULAMADI', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateVideoThumbnail(Media $media): void
    {
        $disk = Storage::disk($media->disk);

        // Geçici dosyalar.
        $tempVideo = tempnam(sys_get_temp_dir(), 'curator-video-').'.mp4';
        $tempThumbnail = tempnam(sys_get_temp_dir(), 'curator-thumb-').'.jpg';

        try {
            /*
             * R2'den videoyu stream ederek local geçici dosyaya indiriyoruz.
             *
             * Storage::get() kullanmıyoruz çünkü bütün videoyu PHP memory'sine
             * almak istemiyoruz.
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
             * FFmpeg:
             *
             * 1. saniyeye git
             * 1 frame al
             * JPEG olarak kaydet
             */
            $process = new Process([
                'ffmpeg',
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

            if (! $process->isSuccessful() || ! file_exists($tempThumbnail)) {
                throw new \RuntimeException(
                    'FFmpeg thumbnail oluşturamadı: '.$process->getErrorOutput()
                );
            }

            /*
             * Thumbnail'ı videonun yanında R2'ye koyuyoruz.
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

            /*
             * Curator'ın mevcut JSON alanını kullanıyoruz.
             * Böylece yeni migration gerekmiyor.
             */
            $curations = $media->curations ?? [];

            $curations['video_thumbnail'] = $thumbnailPath;

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
