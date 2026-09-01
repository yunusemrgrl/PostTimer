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

        if (seconds < start || (end !== null && seconds > end)) {
            continue;
        }

        const b = overlay.bbox || {};
        const padX = videoWidth * 0.015;
        const padY = videoHeight * 0.01;

        const x = Math.max(0, (b.left / 100) * videoWidth - padX);
        const y = Math.max(0, (b.top / 100) * videoHeight - padY);
        const boxW = Math.min(videoWidth - x, ((b.width / 100) * videoWidth) + padX * 2);
        const boxH = Math.min(videoHeight - y, ((b.height / 100) * videoHeight) + padY * 2);

        if (boxW < videoWidth * 0.05 || boxH < videoHeight * 0.015) {
            continue;
        }

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
        status: 'Orijinal video + Turkce sesi tarayicida birlestirir.',
        progress: 0,

        async combine() {
            if (this.busy) return;

            this.busy = true;
            this.progress = 0;
            this.status = 'Hazirlanıyor…';

            try {
                const {
                    ALL_FORMATS,
                    AudioBufferSource,
                    BufferTarget,
                    Conversion,
                    Input,
                    Mp4OutputFormat,
                    Output,
                    Quality,
                    UrlSource,
                } = await import('mediabunny');

                const hasSegments = Array.isArray(this.segments) && this.segments.length > 0;
                const hasOverlays = Array.isArray(this.overlays) && this.overlays.length > 0;
                const burn = (this.burnSubtitles && hasSegments) || hasOverlays;

                this.status = 'Video analiz ediliyor…';
                const input = new Input({ source: new UrlSource(this.videoUrl), formats: ALL_FORMATS });
                const duration = await input.computeDuration();

                this.status = 'Turkce ses hazirlaniyor…';
                this.progress = 5;
                const audioBuffer = await this.prepareDubAudio(this.audioUrl, duration);
                await this.assertAacSupport(audioBuffer.sampleRate, audioBuffer.numberOfChannels);

                const output = new Output({ format: new Mp4OutputFormat(), target: new BufferTarget() });

                const options = {
                    input,
                    output,
                    audio: { discard: true },
                    composable: true,
                };

                if (burn) {
                    let ctx = null;
                    options.video = {
                        codec: 'avc',
                        quality: new Quality({ bitrate: 1500000 }),
                        process: (sample) => {
                            if (!ctx) {
                                const canvas = new OffscreenCanvas(sample.displayWidth, sample.displayHeight);
                                ctx = canvas.getContext('2d');
                            }

                            sample.draw(ctx, 0, 0);

                            drawOverlaysToCanvas(ctx, this.overlays, sample.timestamp, sample.displayWidth, sample.displayHeight);

                            const subtitle = getActiveSubtitle(this.segments, sample.timestamp);
                            if (subtitle) {
                                drawSubtitleToCanvas(ctx, subtitle, sample.displayWidth, sample.displayHeight);
                            }

                            return ctx.canvas;
                        },
                    };
                }

                const conversion = await Conversion.init(options);

                if (!conversion.isValid) {
                    throw new Error('Video bu tarayicida islenemedi (codec/konteyner uyumsuz).');
                }

                conversion.onProgress = (progress) => {
                    this.progress = Math.max(this.progress, 10 + Math.round(progress * 85));
                };

                const audioSource = new AudioBufferSource({
                    codec: 'aac',
                    quality: new Quality({ bitrate: 128000 }),
                });
                output.addAudioTrack(audioSource);

                await output.start();

                this.status = burn ? 'Video altyaziyla yeniden kodlaniyor…' : 'Video kopyalanip ses gomuluyor…';
                await Promise.all([
                    conversion.execute(),
                    audioSource.add(audioBuffer).then(() => audioSource.close()),
                ]);

                await output.finalize();

                this.status = 'Dosya hazirlaniyor…';
                const blob = new Blob([output.target.buffer], { type: 'video/mp4' });
                this.downloadBlob(blob, burn);
            } catch (err) {
                console.error('Dub combine failed:', err);
                this.status = 'Hata: ' + (err.message || 'tekrar deneyin.');
            } finally {
                this.busy = false;
            }
        },

        async prepareDubAudio(url, videoDuration) {
            const audioData = await (await fetch(url, { mode: 'cors' })).arrayBuffer();

            const decodeContext = new (window.AudioContext || window.webkitAudioContext)();
            const decoded = await decodeContext.decodeAudioData(audioData);
            await decodeContext.close();

            const sampleRate = 44100;
            const channels = 2;
            const length = Math.max(1, Math.ceil(videoDuration * sampleRate));

            const offline = new OfflineAudioContext(channels, length, sampleRate);
            const source = offline.createBufferSource();
            source.buffer = decoded;
            source.connect(offline.destination);
            source.start();

            return offline.startRendering();
        },

        async assertAacSupport(sampleRate, numberOfChannels) {
            if (typeof AudioEncoder === 'undefined') {
                throw new Error('Tarayiciniz WebCodecs desteklemiyor. Chrome veya Edge kullanin.');
            }

            const { supported } = await AudioEncoder.isConfigSupported({
                codec: 'mp4a.40.2',
                sampleRate,
                numberOfChannels,
                bitrate: 128000,
            });

            if (!supported) {
                throw new Error('Tarayiciniz AAC ses kodlamasini desteklemiyor. Chrome veya Edge kullanin.');
            }
        },

        downloadBlob(blob, burn) {
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
