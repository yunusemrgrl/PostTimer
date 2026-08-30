/**
 * SRT (SubRip) içeriği üreteci — Gemini segment'lerindeki zaman damgalı
 * Türkçe çevirileri, ffmpeg'in `subtitles` filtresinin okuyacağı SRT
 * formatına çevirir. Pure fonksiyon (test edilebilir).
 *
 * @param array $segments [{'start': float, 'end': float, 'translation': string}, ...]
 */
function buildSRT(segments) {
    const fmt = (seconds) => {
        const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
        const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
        const s = String(Math.floor(seconds % 60)).padStart(2, '0');
        const ms = String(Math.round((seconds % 1) * 1000)).padStart(3, '0');

        return `${h}:${m}:${s},${ms}`;
    };

    return segments
        .filter((seg) => seg && seg.translation)
        .map((seg, i) => `${i + 1}\n${fmt(seg.start)} --> ${fmt(seg.end)}\n${seg.translation}\n`)
        .join('\n');
}

/**
 * Dublaj: orijinal video + Türkçe sesi (ve isteğe bağlı altyazıyı)
 * tarayıcıda birleştirip indirir.
 *
 * ffmpeg.wasm (WebAssembly) tarayıcıda çalışır — sunucuya FFmpeg kurmak
 * gerekmez (serverless uyumlu). Bu modül, Filament'in de üzerinde kurulu
 * olduğu Alpine.js'e bir component register eder; state (status/busy)
 * reactive'tir — plain DOM manipülasyonu yok.
 *
 * burnSubtitles açıkken segment'lerin zaman damgalı Türkçe çevirileri
 * SRT'ye çevrilir ve ffmpeg `subtitles` filtresiyle videoya yakılır
 * (altyazı = burned-in). Bu mod video stream'i yeniden kodlar (libx264);
 * kapalıyken video kopyalanır (-c:v copy, hızlı).
 *
 * Kullanım (blade):
 *   <div x-data="dubCombiner(videoUrl, audioUrl, outputName, segments, burnSubtitles)">
 *     <button x-on:click="combine">…</button>
 *     <span x-text="status"></span>
 *   </div>
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('dubCombiner', (videoUrl, audioUrl, outputName, segments = [], burnSubtitles = false) => ({
        videoUrl,
        audioUrl,
        outputName: outputName || 'dublaj',
        segments,
        burnSubtitles,
        busy: false,
        status: 'Orijinal video + Türkçe sesi tarayıcıda birleştirir.',

        async combine() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.status = 'ffmpeg yükleniyor…';

            try {
                const { FFmpeg } = await import('@ffmpeg/ffmpeg');

                const ffmpeg = new FFmpeg();
                await ffmpeg.load();

                this.status = 'Videolar indiriliyor…';
                const [videoData, audioData] = await Promise.all([
                    (await fetch(this.videoUrl)).arrayBuffer(),
                    (await fetch(this.audioUrl)).arrayBuffer(),
                ]);

                await ffmpeg.writeFile('input.mp4', new Uint8Array(videoData));
                await ffmpeg.writeFile('input.mp3', new Uint8Array(audioData));

                const srt = buildSRT(this.segments);
                const burn = this.burnSubtitles && srt.trim() !== '';

                if (burn) {
                    await ffmpeg.writeFile('subs.srt', srt);
                    this.status = 'Altyazılar yakılıyor…';
                    await ffmpeg.exec([
                        '-i', 'input.mp4',
                        '-i', 'input.mp3',
                        '-i', 'subs.srt',
                        '-filter_complex', '[0:v]subtitles=subs.srt[v]',
                        '-map', '[v]',
                        '-map', '1:a',
                        '-c:v', 'libx264',
                        '-c:a', 'aac',
                        '-shortest',
                        'output.mp4',
                    ]);
                } else {
                    this.status = 'Birleştiriliyor…';
                    await ffmpeg.exec([
                        '-i', 'input.mp4',
                        '-i', 'input.mp3',
                        '-c:v', 'copy',
                        '-c:a', 'aac',
                        '-shortest',
                        'output.mp4',
                    ]);
                }

                this.status = 'Hazırlanıyor…';
                const data = await ffmpeg.readFile('output.mp4');
                const blob = new Blob([data.buffer], { type: 'video/mp4' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = this.outputName.replace(/[^a-zA-Z0-9-_]/g, '_') + '.mp4';
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);

                this.status = '✓ Dublajlı video indirildi.' + (burn ? ' (altyazılı)' : '');
            } catch (err) {
                console.error('Dub combine failed:', err);
                this.status = 'Hata oluştu: tekrar deneyin.';
            } finally {
                this.busy = false;
            }
        },
    }));
});
