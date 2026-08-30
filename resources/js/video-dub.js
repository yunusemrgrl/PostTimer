/**
 * SRT (SubRip) ureteci — segment'lerden SRT formatina cevirir.
 * Pure fonksiyon. SRT export icin tutuldu; altyazi yakma canvas ile yapilir.
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
 * Belirli bir zaman damgasinda aktif altyazi metnini bulur.
 */
function getActiveSubtitle(segments, currentTime) {
    for (const seg of segments) {
        if (currentTime >= (seg.start ?? 0) && currentTime <= (seg.end ?? 0)) {
            return seg.translation || null;
        }
    }
    return null;
}

/**
 * Canvas uzerine Turkce karakter guvenli altyazi cizer.
 *
 * Browser native font engine kullandigi icin I/i/g/s/c/o/u gibi Turkce
 * karakterler ffmpeg libass'tan farkli olarak DAIMMA dogru render edilir.
 * Alt-orta konum, beyaz dolgu + siyah kontur, otomatik satir kaydirma.
 */
function drawSubtitleToCanvas(ctx, text, videoWidth, videoHeight) {
    if (!text || !text.trim()) return;

    const fontSize = Math.round(videoHeight * 0.045);
    const hPadding = Math.round(videoWidth * 0.08);
    const bottomMargin = Math.round(videoHeight * 0.08);
    const maxWidth = videoWidth - hPadding * 2;
    const lineHeight = Math.round(fontSize * 1.4);

    ctx.font = `bold ${fontSize}px sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';

    // Otomatik satir kaydirma
    const words = text.split(/\s+/);
    const lines = [];
    let currentLine = '';

    for (const word of words) {
        const testLine = currentLine ? `${currentLine} ${word}` : word;
        if (ctx.measureText(testLine).width > maxWidth && currentLine) {
            lines.push(currentLine);
            currentLine = word;
        } else {
            currentLine = testLine;
        }
    }
    if (currentLine) lines.push(currentLine);

    // Alt-orta konumda ciz (son satirdan yukari dogru)
    const centerX = videoWidth / 2;
    const baseY = videoHeight - bottomMargin;

    for (let i = lines.length - 1; i >= 0; i--) {
        const y = baseY - (lines.length - 1 - i) * lineHeight;

        // Siyah kontur (outline)
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.85)';
        ctx.lineWidth = Math.max(2, Math.round(fontSize * 0.14));
        ctx.lineJoin = 'round';
        ctx.strokeText(lines[i], centerX, y);

        // Beyaz dolgu
        ctx.fillStyle = 'rgba(255, 255, 255, 1)';
        ctx.fillText(lines[i], centerX, y);
    }
}

/**
 * Dublaj: orijinal video + Turkce sesi (ve istege bagli altyaziyi)
 * tarayicida birlestirip indirir.
 *
 * ffmpeg.wasm (WebAssembly) tarayicida calisir — sunucuya FFmpeg kurmak
 * gerekmez (serverless uyumlu). Alpine.js component olarak register edilir.
 *
 * Altyazi yakma (burnSubtitles=true):
 *   ffmpeg libass/subtitles filtresi KULLANILMAZ — standart ffmpeg.wasm
 *   core'unda font paketlenmedigi icin Turkce karakterler bozuk cikar.
 *   Bunun yerine canvas hard-burn yaklasimi:
 *   1. <video> elementinden kareler seek edilerek cikarilir
 *   2. Her kare canvas'a cizilir + altyazi text overlay (browser native font)
 *   3. Canvas JPEG olarak ffmpeg.wasm FS'ye yazilir
 *   4. ffmpeg: image2 -> libx264 + aac encode (TTS ses ile mux)
 *
 * Altyazi kapali (burnSubtitles=false):
 *   Hizli mux: -c:v copy -c:a aac -shortest (video yeniden kodlanmaz).
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('dubCombiner', (videoUrl, audioUrl, outputName, segments = [], burnSubtitles = false) => ({
        videoUrl,
        audioUrl,
        outputName: outputName || 'dublaj',
        segments,
        burnSubtitles,
        busy: false,
        status: 'Orijinal video + Turkce sesi tarayicida birlestirir.',
        progress: 0,

        async combine() {
            if (this.busy) return;

            this.busy = true;
            this.progress = 0;
            this.status = 'ffmpeg yukleniyor…';

            try {
                const { FFmpeg } = await import('@ffmpeg/ffmpeg');
                const ffmpeg = new FFmpeg();

                ffmpeg.on('progress', ({ progress }) => {
                    this.progress = Math.max(0, Math.min(100, Math.round(progress * 100)));
                });

                await ffmpeg.load();

                // Turkce sesi indir
                this.status = 'Ses indiriliyor…';
                const audioData = await (await fetch(this.audioUrl)).arrayBuffer();
                await ffmpeg.writeFile('input.mp3', new Uint8Array(audioData));

                const hasSegments = Array.isArray(this.segments) && this.segments.length > 0;
                const burn = this.burnSubtitles && hasSegments;

                if (burn) {
                    await this.burnSubtitlesPath(ffmpeg);
                } else {
                    await this.fastMuxPath(ffmpeg);
                }

                // Ciktiyi indir
                this.status = 'Hazirlaniyor…';
                const data = await ffmpeg.readFile('output.mp4');
                this.downloadBlob(data, burn);
            } catch (err) {
                console.error('Dub combine failed:', err);
                this.status = 'Hata: ' + (err.message || 'tekrar deneyin.');
            } finally {
                this.busy = false;
            }
        },

        /**
         * Hizli mux: video kopyalanir, sadece ses gomulur.
         */
        async fastMuxPath(ffmpeg) {
            this.status = 'Video indiriliyor…';
            const videoData = await (await fetch(this.videoUrl)).arrayBuffer();
            await ffmpeg.writeFile('input.mp4', new Uint8Array(videoData));

            this.status = 'Birlestiriliyor…';
            await ffmpeg.exec([
                '-i', 'input.mp4',
                '-i', 'input.mp3',
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-shortest',
                'output.mp4',
            ]);
        },

        /**
         * Canvas overlay ile altyazi yakma (hard-burn):
         * 1. Video kareleri seek ile cikarilir
         * 2. Her kareye canvas uzerinden Turkce-guvenli altyazi cizilir
         * 3. JPEG kareler ffmpeg.wasm ile encode edilir
         *
 * Referans: ffmpeg-webCLI hard-burn yaklasimi (canvas frame overlay).
         */
        async burnSubtitlesPath(ffmpeg) {
            this.status = 'Video kareleri cikariliyor…';

            const video = document.createElement('video');
            video.crossOrigin = 'anonymous';
            video.muted = true;
            video.playsInline = true;
            video.src = this.videoUrl;

            // Metadata bekle
            await new Promise((resolve, reject) => {
                video.onloadedmetadata = resolve;
                video.onerror = () => reject(new Error('Video yuklenemedi (CORS hatasi — R2 CORS header\'lari gerekir).'));
            });

            const w = video.videoWidth;
            const h = video.videoHeight;
            const fps = 24; // Altyazi icin yeterli, islem suresini kisaltir
            const totalFrames = Math.min(Math.ceil(video.duration * fps), 2400);

            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');

            for (let i = 0; i < totalFrames; i++) {
                const time = i / fps;

                // Seek to frame
                video.currentTime = time;
                await new Promise((resolve) => {
                    video.onseeked = resolve;
                });

                // Video karesini canvas'a ciz
                ctx.drawImage(video, 0, 0, w, h);

                // Altyazi overlay (Turkce karakter guvenli, browser native font)
                const subtitle = getActiveSubtitle(this.segments, time);
                if (subtitle) {
                    drawSubtitleToCanvas(ctx, subtitle, w, h);
                }

                // JPEG olarak ffmpeg FS'ye yaz
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
                const arr = new Uint8Array(await blob.arrayBuffer());
                await ffmpeg.writeFile(`frame_${String(i).padStart(6, '0')}.jpg`, arr);

                this.status = `Kareler isleniyor… ${i + 1}/${totalFrames}`;
                this.progress = Math.round(((i + 1) / totalFrames) * 70);
            }

            // Encode: JPEG kareler + TTS ses -> MP4
            this.status = 'Video encode ediliyor…';
            await ffmpeg.exec([
                '-framerate', String(fps),
                '-i', 'frame_%06d.jpg',
                '-i', 'input.mp3',
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-shortest',
                'output.mp4',
            ]);

            // Gecici kareleri temizle
            for (let i = 0; i < totalFrames; i++) {
                await ffmpeg.deleteFile(`frame_${String(i).padStart(6, '0')}.jpg`);
            }
        },

        downloadBlob(data, burn) {
            const blob = new Blob([data.buffer], { type: 'video/mp4' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = this.outputName.replace(/[^a-zA-Z0-9-_]/g, '_') + '.mp4';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            this.status = 'Dublajli video indirildi.' + (burn ? ' (altyazili)' : '');
            this.progress = 100;
        },
    }));
});

/**
 * Wavesurfer.js waveform onizleme component'i (Alpine).
 *
 * BSD-3-Clause licansli wavesurfer.js ile Turkce sesin waveform'unu
 * gosterir. Lazy load — ilk render'da import edilir, x-init ile baslatilir.
 *
 * Kullanim (blade):
 *   <div x-data="audioWaveformer(@js($audioUrl))" x-init="init()">
 *     <div x-ref="waveform"></div>
 *     <button x-on:click="toggle" x-text="playing ? 'Durdur' : 'Oynat'"></button>
 *   </div>
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('audioWaveformer', (audioUrl) => ({
        audioUrl,
        wavesurfer: null,
        playing: false,
        ready: false,

        async init() {
            const WaveSurfer = (await import('wavesurfer.js')).default;
            this.wavesurfer = WaveSurfer.create({
                container: this.$refs.waveform,
                waveColor: '#94a3b8',
                progressColor: '#6366f1',
                barWidth: 2,
                barRadius: 1,
                height: 48,
                url: this.audioUrl,
            });

            this.wavesurfer.on('ready', () => {
                this.ready = true;
            });

            this.wavesurfer.on('audioprocess', () => {
                this.playing = true;
            });

            this.wavesurfer.on('finish', () => {
                this.playing = false;
            });
        },

        toggle() {
            if (!this.wavesurfer) return;
            this.wavesurfer.playPause();
            this.playing = this.wavesurfer.isPlaying();
        },
    }));
});
