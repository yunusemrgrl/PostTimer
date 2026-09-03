function getActiveSubtitle(segments, currentTime) {
    for (const seg of segments) {
        if (currentTime >= (seg.start ?? 0) && currentTime <= (seg.end ?? 0)) {
            return seg.translation || null;
        }
    }
    return null;
}

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
    const lines = wrapLines(ctx, text, maxWidth);
    const centerX = videoWidth / 2;
    const baseY = videoHeight - bottomMargin;
    for (let i = lines.length - 1; i >= 0; i--) {
        const y = baseY - (lines.length - 1 - i) * lineHeight;
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.85)';
        ctx.lineWidth = Math.max(2, Math.round(fontSize * 0.14));
        ctx.lineJoin = 'round';
        ctx.strokeText(lines[i], centerX, y);
        ctx.fillStyle = 'rgba(255, 255, 255, 1)';
        ctx.fillText(lines[i], centerX, y);
    }
}

function drawOverlaysToCanvas(ctx, overlays, seconds, videoWidth, videoHeight) {
    for (const overlay of overlays || []) {
        const start = overlay.start ?? 0;
        const end = overlay.end ?? null;
        if (seconds < start || (end !== null && seconds > end)) continue;
        const b = overlay.bbox || {};
        const padX = videoWidth * 0.015;
        const padY = videoHeight * 0.01;
        const x = Math.max(0, (b.left / 100) * videoWidth - padX);
        const y = Math.max(0, (b.top / 100) * videoHeight - padY);
        const boxW = Math.min(videoWidth - x, ((b.width / 100) * videoWidth) + padX * 2);
        const boxH = Math.min(videoHeight - y, ((b.height / 100) * videoHeight) + padY * 2);
        if (boxW < videoWidth * 0.05 || boxH < videoHeight * 0.015) continue;
        const radius = Math.min(boxH * 0.2, 16);
        ctx.save();
        ctx.beginPath();
        ctx.roundRect(x, y, boxW, boxH, radius);
        ctx.fillStyle = 'rgba(255, 255, 255, 0.97)';
        ctx.shadowColor = 'rgba(0, 0, 0, 0.25)';
        ctx.shadowBlur = Math.round(videoHeight * 0.006);
        ctx.fill();
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.08)';
        ctx.lineWidth = 1;
        ctx.stroke();
        const innerW = boxW * 0.88;
        const innerH = boxH * 0.8;
        let fontSize = Math.min(innerH * 0.55, videoHeight * 0.045);
        let lineHeight = fontSize * 1.3;
        ctx.font = `bold ${Math.round(fontSize)}px sans-serif`;
        let lines = wrapLines(ctx, overlay.translation, innerW);
        while (lines.length * lineHeight > innerH && fontSize > videoHeight * 0.012) {
            fontSize -= Math.max(1, Math.round(fontSize * 0.06));
            ctx.font = `bold ${Math.round(fontSize)}px sans-serif`;
            lines = wrapLines(ctx, overlay.translation, innerW);
            lineHeight = fontSize * 1.3;
        }
        ctx.fillStyle = 'rgba(17, 17, 17, 1)';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        const centerX = x + boxW / 2;
        const totalHeight = lines.length * lineHeight;
        let lineY = y + (boxH - totalHeight) / 2 + lineHeight / 2;
        for (const line of lines) {
            ctx.fillText(line, centerX, lineY);
            lineY += lineHeight;
        }
        ctx.restore();
    }
}

function wrapLines(ctx, text, maxWidth) {
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
    return lines;
}

function registerAlpineComponent(name, definition) {
    const register = () => Alpine.data(name, definition);
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register, { once: true });
    }
}

/**
 * Dub combiner — Canvas + MediaRecorder yaklaşımı.
 *
 * Neden mediabunny/WebCodecs değil?
 * WebCodecs VideoDecoder bazı codec'leri (özellikle headless Chrome ve bazı
 * gerçek tarayıcı konfigürasyonlarında) decode edemez — "undecodable_source_codec"
 * hatası verir. <video> elementi ise tarayıcının native decoder'ını kullanır;
 * video oynatılabiliyorsa decode edilebiliyor demektir.
 *
 * Akış:
 *  1. <video> elementi oluştur, native decoder ile yükle
 *  2. AudioContext ile orijinal ses + dublaj sesini mix'le
 *  3. Her frame'de video'yu canvas'a çiz, overlay/subtitle ekle
 *  4. canvas.captureStream() + audio destination stream → MediaRecorder
 *  5. Video bitince WebM blob olarak indir veya önizle
 *
 * Not: Çıktı WebM olur (MP4 encode AAC+H.264 gerektirir, bu da WebCodecs
 * gerektirir — kısır döngü). Server-side MP4 dönüşümü istenirse ayrıca
 * endpoint eklenebilir.
 */
registerAlpineComponent('dubCombiner', (videoUrl, audioUrl, outputName, segments = [], burnSubtitles = false, overlays = []) => ({
    videoUrl,
    audioUrl,
    outputName: outputName || 'dublaj',
    segments,
    overlays: Array.isArray(overlays)
        ? overlays.filter((o) => o && o.bbox && o.translation)
        : [],
    burnSubtitles,
    busy: false,
    status: audioUrl
        ? 'Orijinal video + dublaj sesini tarayıcıda birleştirir.'
        : 'Ekran yazılarını Türkçe\'ye çevirir, orijinal sesi korur.',
    progress: 0,
    previewUrl: null,
    showPreview: false,
    _cleanupFns: [],

    async combine(mode = 'download') {
        if (this.busy) return;
        this.busy = true;
        this.progress = 0;
        this.status = 'Hazırlanıyor…';
        this._cleanupFns = [];

        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }

        // Ön kontrol: MediaRecorder gerekli
        if (typeof MediaRecorder === 'undefined') {
            this.status = 'Hata: Tarayıcınız MediaRecorder desteklemiyor. Chrome/Edge/Firefox kullanın.';
            this.busy = false;
            return;
        }

        let video = null;
        let audioCtx = null;
        let recorder = null;

        try {
            const dubAudio = Boolean(this.audioUrl);
            const hasSegments = Array.isArray(this.segments) && this.segments.length > 0;
            const hasOverlays = Array.isArray(this.overlays) && this.overlays.length > 0;
            const burn = (this.burnSubtitles && hasSegments) || hasOverlays;

            // 1) Video elementi — muted başlat, sesi AudioContext ile yöneteceğiz
            video = document.createElement('video');
            video.crossOrigin = 'anonymous';
            video.muted = false; // AudioContext sesini duymak için muted olmamalı
            video.playsInline = true;
            video.preload = 'auto';
            // Sesin AudioContext'e gidebilmesi için playsInline + muted=false
            // ama kullanıcıya duyurmamak için volume 0 yapacağız
            video.volume = 0;
            video.src = this.videoUrl;

            await new Promise((resolve, reject) => {
                const onReady = () => {
                    cleanup();
                    if (video.videoWidth && video.videoHeight) resolve();
                    else reject(new Error('Video boyutları okunamadı'));
                };
                const onError = () => {
                    cleanup();
                    reject(new Error('Video yüklenemedi (HTTP/CORS)'));
                };
                const cleanup = () => {
                    video.removeEventListener('loadedmetadata', onReady);
                    video.removeEventListener('error', onError);
                };
                video.addEventListener('loadedmetadata', onReady);
                video.addEventListener('error', onError);
                video.load();
            });

            const width = video.videoWidth;
            const height = video.videoHeight;
            const duration = video.duration || 0;
            if (!duration || !isFinite(duration)) {
                throw new Error('Video süresi belirsiz — canlı yayın dosyaları desteklenmez.');
            }

            // 2) Canvas
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d', { alpha: false });

            // 3) AudioContext — orijinal ses + dublaj mix
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) throw new Error('AudioContext desteklenmiyor.');
            audioCtx = new AudioCtx();
            if (audioCtx.state === 'suspended') await audioCtx.resume();

            const destination = audioCtx.createMediaStreamDestination();

            // Orijinal ses: MediaElementSource ile video elementinden al
            // Not: createMediaElementSource bir kez çağrılabilir; video elementini
            // de "hoparlör" yerine destination'a yönlendiriyoruz ki kullanıcıya
            // gerçek zamanlı duyulmasın (sadece kayda gitsin).
            let videoSource = null;
            try {
                videoSource = audioCtx.createMediaElementSource(video);
                videoSource.connect(destination);
                // Hoparlöre bağlama — sadece kayda gitsin
            } catch (e) {
                // Zaten başka context'e bağlıysa — orijinal ses kayba uğrar ama
                // dublaj varsa problem yok. Yoksa kullanıcıya bilgi ver.
                console.warn('MediaElementSource oluşturulamadı:', e.message);
                if (!dubAudio) {
                    throw new Error('Orijinal ses alınamadı (video başka ses sistemine bağlı).');
                }
            }

            // Dublaj sesi
            let dubBufferSource = null;
            if (dubAudio) {
                this.status = 'Dublaj sesi hazırlanıyor…';
                this.progress = 5;
                const response = await fetch(this.audioUrl, { mode: 'cors' });
                if (!response.ok) throw new Error('Dublaj sesi indirilemedi: HTTP ' + response.status);
                const audioData = await response.arrayBuffer();
                const decoded = await audioCtx.decodeAudioData(audioData);
                dubBufferSource = audioCtx.createBufferSource();
                dubBufferSource.buffer = decoded;
                dubBufferSource.connect(destination);
            }

            // 4) MediaRecorder — canvas stream + audio stream birleştir
            const fps = Math.min(30, Math.round(video.getFrameRate?.() || 30));
            const canvasStream = canvas.captureStream(fps);
            const audioTracks = destination.stream.getAudioTracks();
            const combinedStream = new MediaStream([
                ...canvasStream.getVideoTracks(),
                ...audioTracks,
            ]);

            let mimeType = 'video/webm;codecs=vp9,opus';
            if (!MediaRecorder.isTypeSupported(mimeType)) mimeType = 'video/webm;codecs=vp8,opus';
            if (!MediaRecorder.isTypeSupported(mimeType)) mimeType = 'video/webm';
            if (!MediaRecorder.isTypeSupported(mimeType)) {
                throw new Error('Tarayıcı WebM kaydını desteklemiyor.');
            }

            recorder = new MediaRecorder(combinedStream, {
                mimeType,
                videoBitsPerSecond: 2500000,
                audioBitsPerSecond: 128000,
            });

            const chunks = [];
            recorder.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) chunks.push(e.data);
            };

            const recordingDone = new Promise((resolve, reject) => {
                recorder.onstop = () => {
                    try {
                        const blob = new Blob(chunks, { type: 'video/webm' });
                        resolve(blob);
                    } catch (err) {
                        reject(err);
                    }
                };
                recorder.onerror = (e) => reject(e.error || new Error('Recorder hatası'));
            });

            // 5) Frame çizme döngüsü
            const drawFrame = () => {
                try {
                    ctx.drawImage(video, 0, 0, width, height);
                    drawOverlaysToCanvas(ctx, this.overlays, video.currentTime, width, height);
                    if (burn && hasSegments) {
                        const subtitle = getActiveSubtitle(this.segments, video.currentTime);
                        if (subtitle) drawSubtitleToCanvas(ctx, subtitle, width, height);
                    }
                    if (duration > 0) {
                        this.progress = Math.min(95, 5 + Math.round((video.currentTime / duration) * 90));
                    }
                } catch (e) {
                    console.warn('drawFrame hatası:', e);
                }
            };

            // İlk frame'i hemen çiz (video henüz oynamadan)
            drawFrame();

            // requestVideoFrameCallback varsa onu kullan (daha verimli, sadece
            // yeni frame geldiğinde tetiklenir); yoksa requestAnimationFrame.
            let rafId = null;
            let rvfcId = null;
            let stopped = false;

            const scheduleFrame = () => {
                if (stopped) return;
                if (typeof video.requestVideoFrameCallback === 'function') {
                    rvfcId = video.requestVideoFrameCallback(() => {
                        drawFrame();
                        scheduleFrame();
                    });
                } else {
                    const loop = () => {
                        if (stopped) return;
                        drawFrame();
                        rafId = requestAnimationFrame(loop);
                    };
                    rafId = requestAnimationFrame(loop);
                }
            };

            // Video bitince recorder'ı durdur
            const onEnded = () => {
                drawFrame(); // son frame
                setTimeout(() => {
                    if (recorder && recorder.state !== 'inactive') {
                        recorder.stop();
                    }
                }, 150);
            };
            video.addEventListener('ended', onEnded, { once: true });

            // Cleanup listesi
            this._cleanupFns.push(() => {
                stopped = true;
                if (rafId) cancelAnimationFrame(rafId);
                if (rvfcId && typeof video.cancelVideoFrameCallback === 'function') {
                    try { video.cancelVideoFrameCallback(rvfcId); } catch (e) {}
                }
                canvasStream.getTracks().forEach((t) => t.stop());
            });

            // 6) Başlat
            this.status = burn
                ? 'Video yazıyla yeniden kodlanıyor…'
                : (dubAudio ? 'Video + ses birleştiriliyor…' : 'Video kopyalanıyor…');

            recorder.start(1000); // her saniye chunk al (bellek şişmesin)

            // Dublajı video başlangıcında başlat
            if (dubBufferSource) {
                try { dubBufferSource.start(0); } catch (e) { console.warn(e); }
            }

            // Videoyu oynat + frame çizmeye başla
            try {
                await video.play();
            } catch (e) {
                throw new Error('Video oynatılamadı (autoplay engeli?): ' + e.message);
            }
            scheduleFrame();

            // 7) Bitene kadar bekle
            const blob = await recordingDone;

            // 8) Sonuç
            if (mode === 'preview') {
                if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
                this.previewUrl = URL.createObjectURL(blob);
                this.showPreview = true;
                this.status = 'Dublaj hazır — yukarıdaki oynatıcıdan izleyebilirsin.';
                this.progress = 100;
                return;
            }

            this.downloadBlob(blob, burn);
        } catch (err) {
            console.error('Dub combine failed:', err);
            this.status = 'Hata: ' + (err.message || 'tekrar deneyin.');
        } finally {
            // Cleanup
            for (const fn of this._cleanupFns) {
                try { fn(); } catch (e) {}
            }
            this._cleanupFns = [];
            if (video) {
                try { video.pause(); } catch (e) {}
                video.removeAttribute('src');
                video.load();
                video.remove();
            }
            if (audioCtx && audioCtx.state !== 'closed') {
                try { audioCtx.close(); } catch (e) {}
            }
            this.busy = false;
        }
    },

    downloadBlob(blob, burn) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = this.outputName.replace(/[^a-zA-Z0-9-_]/g, '_') + '.webm';
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
        this.status = 'Dublajlı video indirildi.' + (burn ? ' (altyazılı)' : '');
        this.progress = 100;
    },
}));

registerAlpineComponent('audioWaveformer', (audioUrl) => ({
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
        this.wavesurfer.on('ready', () => { this.ready = true; });
        this.wavesurfer.on('audioprocess', () => { this.playing = true; });
        this.wavesurfer.on('finish', () => { this.playing = false; });
    },
    toggle() {
        if (!this.wavesurfer) return;
        this.wavesurfer.playPause();
        this.playing = this.wavesurfer.isPlaying();
    },
}));