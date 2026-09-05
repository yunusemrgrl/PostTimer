import {
    ALL_FORMATS,
    AudioBufferSink,
    AudioBufferSource,
    BlobSource,
    BufferTarget,
    CanvasSource,
    Input,
    Mp4OutputFormat,
    Output,
    Quality,
    canEncodeAudio,
    canEncodeVideo,
} from 'mediabunny';

function getActiveSubtitle(segments, currentTime) {
    for (const seg of segments) {
        if (currentTime >= (seg.start ?? 0) && currentTime < (seg.end ?? 0)) {
            return seg.translation || null;
        }
    }
    return null;
}

function drawSubtitleToCanvas(ctx, text, videoWidth, videoHeight, lineCache) {
    if (!text || !text.trim()) return;
    const fontSize = Math.round(videoHeight * 0.045);
    const hPadding = Math.round(videoWidth * 0.08);
    const bottomMargin = Math.round(videoHeight * 0.08);
    const maxWidth = videoWidth - hPadding * 2;
    const lineHeight = Math.round(fontSize * 1.4);
    ctx.font = `bold ${fontSize}px sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'alphabetic';
    // Aynı segment ekranda kaldığı sürece (genelde birkaç saniye = onlarca
    // kare) metin ve satır bölme aynı sonucu verir — gerçek zamanlı döngüde
    // her karede yeniden ölçmek yerine önbellekten okuyoruz.
    let lines;
    if (lineCache) {
        lines = lineCache.get(text);
        if (!lines) {
            lines = wrapLines(ctx, text, maxWidth);
            lineCache.set(text, lines);
        }
    } else {
        lines = wrapLines(ctx, text, maxWidth);
    }
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

// video içeriğinden uzun çıkmasına (15sn → 22sn) yol açan asıl sebep buydu.
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
// Gerçek zamanlı döngüde çağrılır
function drawFrameOverlays(ctx, overlays, seconds, videoWidth, videoHeight) {
    for (const ov of overlays || []) {
        drawStyledOverlay(ctx, ov.translation, ov.style || {}, videoWidth, videoHeight, ov.start ?? 0, ov.end ?? null, seconds);
    }
}


function parseRGBA(rgba) {
    const m = rgba.match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const parts = m[1].split(',').map(s => parseFloat(s.trim()));
    return { r: parts[0] || 0, g: parts[1] || 0, b: parts[2] || 0, a: parts[3] ?? 1 };
}

// Gemini'den gelen stil objesine göre overlay çizer.
// style: { fontFamily, fontWeight, fontSize, fontStyle, color, backgroundColor,
//          textAlign, padding, borderRadius, maxWidth, textShadow, opacity }
function drawStyledOverlay(ctx, text, style, videoWidth, videoHeight, start, end, seconds) {
    // Bitiş DAHİL DEĞİL [start, end): Gemini timestamp'leri tam sayıya
    // yuvarladığında (ör. 3.12 -> 3) ardışık kayıtlar aynı anda aktif olmasın.
    if (seconds < start || (end !== null && seconds >= end)) return;

    const font = style.fontFamily || 'Inter, sans-serif';
    const weight = style.fontWeight || '600';
    const size = style.fontSize || Math.round(videoHeight * 0.035);
    const fstyle = style.fontStyle || 'normal';
    const color = style.color || '#FFFFFF';
    const bg = style.backgroundColor || 'rgba(0,0,0,0.85)';
    const align = style.textAlign || 'center';
    const padding = parseFloat(String(style.padding)) || Math.round(videoWidth * 0.02);
    const radius = parseFloat(String(style.borderRadius)) || Math.min(size * 0.4, 16);
    const maxWidthPct = parseFloat(String(style.maxWidth)) || 90;
    const textShadow = style.textShadow && style.textShadow !== 'none' ? style.textShadow : null;
    // ORIJINAL YAZI VIDEODAN SILINEMEZ — overlay onu örten tek araç. Bu yüzden
    // yarı saydam stil (opacity < 1 / rgba alpha < 1) Gemini'den gelse bile
    // ZORUNLU olarak opaklaştırılır; yoksa alttan orijinal metin sızar.
    const opacity = style.opacity !== undefined ? parseFloat(String(style.opacity)) : 1;

    const fontStr = `${fstyle} ${weight} ${size}px ${font}`;
    ctx.font = fontStr;
    ctx.textAlign = align;
    ctx.textBaseline = 'middle';

    const maxWidthPx = (maxWidthPct / 100) * videoWidth - padding * 2;
    const lines = wrapLines(ctx, text, maxWidthPx);
    const lineHeight = size * 1.3;
    const totalHeight = lines.length * lineHeight;

    const boxW = Math.min(videoWidth * (maxWidthPct / 100), maxWidthPx + padding * 2);
    const boxH = totalHeight + padding * 2;
    const boxX = (videoWidth - boxW) / 2;
    const boxY = videoHeight * 0.15; // üst bölgede tut (Gemini bbox vermese de)

    ctx.save();
    ctx.globalAlpha = 1; // daima opak — overlay orijinal yazıyı örten tek araç

    // Beyaz/gri bulut arka plan (alpha zorla 1'e çekilir)
    const bgColorRaw = parseRGBA(bg) || { r: 0, g: 0, b: 0, a: 0.85 };
    const bgColor = { ...bgColorRaw, a: 1 };
    ctx.beginPath();
    ctx.roundRect(boxX, boxY, boxW, boxH, radius);
    ctx.fillStyle = `rgba(${bgColor.r},${bgColor.g},${bgColor.b},${bgColor.a})`;
    ctx.shadowColor = 'rgba(0, 0, 0, 0.3)';
    ctx.shadowBlur = Math.round(videoHeight * 0.006);
    ctx.fill();
    ctx.shadowBlur = 0;

    // Kenarlık (bulutun kendi renginde)
    ctx.strokeStyle = `rgba(${bgColor.r},${bgColor.g},${bgColor.b},${Math.min(bgColor.a + 0.1, 1)})`;
    ctx.lineWidth = 2;
    ctx.stroke();

    // Metin
    ctx.fillStyle = color;
    if (textShadow) {
        ctx.shadowColor = 'rgba(0,0,0,0.6)';
        ctx.shadowBlur = 4;
        ctx.shadowOffsetX = 1;
        ctx.shadowOffsetY = 1;
    }
    const firstLineY = boxY + padding + lineHeight / 2;
    let lineY = firstLineY;
    for (const line of lines) {
        ctx.fillText(line, boxX + boxW / 2, lineY);
        lineY += lineHeight;
    }

    ctx.restore();
}

// ---------------------------------------------------------------------------
// Encode desteği + offline ses pipeline'ı yardımcıları
// ---------------------------------------------------------------------------

// H.264 (avc) video + AAC ses ENCODE desteği yoksa net hata ver. Opus fallback
// bilinçli olarak YOK: Instagram/iOS AAC ister, opus'lu MP4 hedef
// platformlarda oynamaz (bkz. dubCombiner başındaki not).
async function assertEncoderSupport(width, height) {
    let videoOk = false;
    let audioOk = false;
    try {
        videoOk = await canEncodeVideo('avc', { width, height });
        audioOk = await canEncodeAudio('aac', { numberOfChannels: 2, sampleRate: 44100 });
    } catch (e) {
        console.warn('Codec destek sorgusu başarısız:', e);
    }
    if (!videoOk || !audioOk) {
        throw new Error(
            'Tarayıcınız ' + (!videoOk ? 'H.264 video' : 'AAC ses') +
            ' kodlamasını desteklemiyor. MP4 çıktısı için Chrome/Edge/Safari kullanın.',
        );
    }
}

// decodeAudioData için izole bir OfflineAudioContext: render'a gerek kalmadan
// decode yapar, autoplay policy'sine takılmaz, kullanıcıya ses duyurmaz.
// Desteklenmeyen/bozuk ses için null döner.
async function decodeAudioBuffer(arrayBuffer) {
    try {
        const ctx = new OfflineAudioContext(1, 1, 44100);
        return await ctx.decodeAudioData(arrayBuffer);
    } catch (e) {
        return null;
    }
}

// Orijinal ses decode: önce native demuxer (decodeAudioData video
// konteynerinden ses track'ini çıkarır — MP4/AAC ve WebM/Opus'ta çalışır),
// konteyneri çözemezse Mediabunny AudioBufferSink (WebCodecs AudioDecoder).
// İkisi de başarısızsa [] döner — sessiz devam (videoda ses olmayabilir; bu
// bir hata değildir).
// Dönüş: [{ buffer: AudioBuffer, timestamp: number }] — mixAudioOffline bunu
// tek formatta tüketir.
async function decodeOriginalAudio(videoBlob) {
    const bytes = await videoBlob.arrayBuffer();
    const native = await decodeAudioBuffer(bytes.slice(0));
    if (native) return [{ buffer: native, timestamp: 0 }];

    try {
        const input = new Input({ source: new BlobSource(videoBlob), formats: ALL_FORMATS });
        const track = await input.getPrimaryAudioTrack();
        if (!track || !(await track.canDecode())) return [];
        const sink = new AudioBufferSink(track);
        const chunks = [];
        for await (const { buffer, timestamp } of sink.buffers()) {
            chunks.push({ buffer, timestamp });
        }
        return chunks;
    } catch (e) {
        console.warn('AudioBufferSink fallback başarısız:', e);
        return [];
    }
}

// Orijinal + dublajı tek stereo 44.1kHz AudioBuffer'da birleştir:
// OfflineAudioContext tek render'da resample + pad + mix yapar. Realtime
// capture olmadığı için arka plan sekmesinden tamamen bağımsızdır ve
// oynatma hızına bağlı senkron hatası üretmez. Hiç ses kaynağı yoksa null
// döner (MP4 ses track'siz yazılır).
async function mixAudioOffline({ original, dub, duration }) {
    if ((!original || original.length === 0) && !dub) return null;
    const sampleRate = 44100;
    const length = Math.max(1, Math.ceil(duration * sampleRate));
    const ctx = new OfflineAudioContext({ numberOfChannels: 2, length, sampleRate });

    for (const { buffer, timestamp } of original || []) {
        const source = ctx.createBufferSource();
        source.buffer = buffer;
        source.connect(ctx.destination);
        source.start(timestamp);
    }
    if (dub) {
        const source = ctx.createBufferSource();
        source.buffer = dub;
        source.connect(ctx.destination);
        source.start(0);
    }
    return ctx.startRendering();
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
 * Dub combiner — <video> (native decode) + WebCodecs (encode) + Mediabunny (mux).
 *
 * Decode ve encode bilinçli olarak AYRI katmanlarda:
 * - Decode: WebCodecs VideoDecoder bazı kaynak codec'leri decode edemez
 *   ("undecodable_source_codec" — headless Chrome dahil). <video> elementi
 *   tarayıcının native decoder'ını kullanır; video oynatılabiliyorsa decode
 *   edilebiliyor demektir. Kaynak video bu yüzden ASLA WebCodecs ile decode
 *   edilmez (86b21e6'daki Mediabunny Conversion path'i bu yüzden geri alındı).
 * - Encode: WebCodecs VideoEncoder (H.264) + AudioEncoder (AAC) — ayrı
 *   thread'lerde çalışır, main thread throttle'ından etkilenmez.
 * - Mux: Mediabunny Output + Mp4OutputFormat (fastStart: 'in-memory') +
 *   BufferTarget → çıktı her yerde oynayan MP4 (H.264 + AAC). Instagram/iOS
 *   AAC ister; AAC desteklenmeyen tarayıcıda opus fallback YOK, net hata ver.
 *
 * Ses pipeline'ı TAMAMEN offline (realtime capture yok):
 *   video blob → decodeAudioData (native demuxer; konteyneri çözemezse
 *   Mediabunny AudioBufferSink fallback) → OfflineAudioContext'te dublajla
 *   mix/resample/pad → tek AudioBuffer → AudioBufferSource (AAC 128k).
 *   Arka plan sekmesinden etkilenmez; createMediaElementSource /
 *   MediaStreamDestination / realtime senkron hataları sınıfı tamamen kalkar.
 *
 * Video frame'leri: <video>.play() → requestVideoFrameCallback → canvas'a çiz
 * (subtitle/overlay) → CanvasSource.add(mediaTime) → H.264. Timestamp'ler
 * mediaTime tabanlıdır (saniye, mikro-değil) — VFR kaynaklar doğru mux'lanır,
 * captureStream'in dahili sabit zamanlayıcısı (2.12s webm sorununun kök
 * nedeni) devre dışı kalır.
 *
 * BİLİNÇLİ SINIR: Video decode hâlâ realtime (oynatma) olduğu için sekme
 * arka plana alınırsa rVFC/video oynatması durur — kayıt takılır ve
 * stallInterval eldeki veriyle nazikçe bitirir. Yani bu rewrite ÇIKTI
 * FORMATINI (WebM → MP4) ve SES pipeline'INI (realtime → offline) çözer;
 * arka plan sekmesi throttle'ını çözmez. Kalıcı çözüm hybrid decode gerektirir
 * (önce WebCodecs VideoDecoder dene, düşerse <video>'ya dön) — follow-up.
 *
 * Akış:
 *  1. Video blob'unu indir (CORS-safe same-origin proxy) + <video> metadata
 *  2. canEncodeVideo('avc') + canEncodeAudio('aac') → destek yoksa fail-fast
 *  3. Orijinal ses (offline decode) + dublaj → OfflineAudioContext mix
 *  4. Mediabunny Output (MP4) + CanvasSource (H.264) + AudioBufferSource (AAC)
 *  5. rVFC döngüsü: canvas'a çiz → CanvasSource.add(mediaTime)
 *  6. 'ended' → finalize → MP4 blob: indir veya önizle
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
    encodeFps: null,
    previewUrl: null,
    showPreview: false,
    showDubbed: false,
    _cleanupFns: [],

    async combine(mode = 'download') {
        if (this.busy) return;
        this.busy = true;
        this.progress = 0;
        this.encodeFps = null;
        this.status = 'Hazırlanıyor…';
        this.showDubbed = true; // üretim akışı başlarken "Dublajlı Sonuç" sekmesine geç
        this._cleanupFns = [];

        if (this.previewUrl) {
            URL.revokeObjectURL(this.previewUrl);
            this.previewUrl = null;
        }

        // Ön kontrol: WebCodecs ENCODE (H.264 + AAC) gerekli. Decode için
        // WebCodecs GEREKMEZ — kaynak <video> elementinin native decoder'ından
        // gelir (bkz. dosya başındaki not).
        if (typeof VideoEncoder === 'undefined' || typeof AudioEncoder === 'undefined') {
            this.status = 'Hata: Tarayıcınız WebCodecs desteklemiyor. Chrome/Edge/Safari kullanın.';
            this.busy = false;
            return;
        }

        // Video URL'ini CORS problemlerinden kurtarmak için önce same-origin
        // blob URL'e çeviriyoruz. CDN'den gelen cross-origin videolar
        // mediabunny/HTMLCanvasElement gibi yerlerde çalışmıyor.
        let videoBlob = null;
        this.status = 'Video hazırlanıyor…';
        try {
            const videoResponse = await fetch(this.videoUrl, {method: 'GET'});
            if (!videoResponse.ok) {
                throw new Error(
                    'Video indirilemedi (HTTP ' + videoResponse.status + '): ' + this.videoUrl,
                );
            }
            videoBlob = await videoResponse.blob();
            if (videoBlob.size === 0) {
                throw new Error('Video bozuk (0 byte). Lütfen daha sonra tekrar deneyin.');
            }
            if (this._videoProxyUrl) URL.revokeObjectURL(this._videoProxyUrl);
            this._videoProxyUrl = URL.createObjectURL(videoBlob);
            this._cleanupFns.push(() => {
                if (this._videoProxyUrl) {
                    URL.revokeObjectURL(this._videoProxyUrl);
                    this._videoProxyUrl = null;
                }
            });
        } catch (err) {
            this.status = 'Hata: ' + (err.message || 'Video yüklenemedi.');
            this.busy = false;
            return;
        }

        let video = null;
        let output = null;
        let canvasSource = null;
        let audioSource = null;

        try {
            const dubAudio = Boolean(this.audioUrl);
            const hasSegments = Array.isArray(this.segments) && this.segments.length > 0;
            const hasOverlays = Array.isArray(this.overlays) && this.overlays.length > 0;
            const burn = (this.burnSubtitles && hasSegments) || hasOverlays;

            // 1) Video elementi — SADECE decode/metadata için; sesini hiçbir
            // zaman elementten realtime almıyoruz (offline ses pipeline'ı var).
            // muted: kullanıcıya ses duyurmaz + autoplay policy'lerini geçer.
            video = document.createElement('video');
            video.crossOrigin = 'anonymous';
            video.muted = true;
            video.playsInline = true;
            video.preload = 'auto';
            video.src = this._videoProxyUrl || this.videoUrl;

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

            // 2) Encode desteği — gerçek çözünürlükle sorgula. H.264 (avc) +
            //    AAC yoksa net hata: Instagram/iOS AAC ister, opus fallback
            //    bilinçli olarak YOK.
            this.status = 'Tarayıcı codec desteği kontrol ediliyor…';
            await assertEncoderSupport(width, height);

            // 3) Canvas
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d', { alpha: false });

            // 4) Ses pipeline'ı — tamamen OFFLINE (realtime capture yok):
            //    orijinal ses + dublaj → OfflineAudioContext'te mix/resample/pad
            //    → tek AudioBuffer. Arka plan sekmesinden etkilenmez; ses-video
            //    senkronu oynatma hızına bağlı değildir.
            this.status = 'Ses hazırlanıyor…';
            this.progress = 5;

            let originalChunks = [];
            try {
                originalChunks = await decodeOriginalAudio(videoBlob);
            } catch (e) {
                console.warn('Orijinal ses decode edilemedi (sessiz devam):', e);
            }

            let dubBuffer = null;
            if (dubAudio) {
                const response = await fetch(this.audioUrl, { mode: 'cors' });
                if (!response.ok) throw new Error('Dublaj sesi indirilemedi: HTTP ' + response.status);
                const audioData = await response.arrayBuffer();
                dubBuffer = await decodeAudioBuffer(audioData);
                if (!dubBuffer) {
                    throw new Error('Dublaj sesi decode edilemedi (biçim desteklenmiyor olabilir).');
                }
            }

            const mixBuffer = await mixAudioOffline({
                original: originalChunks,
                dub: dubBuffer,
                duration,
            });

            // 5) Mediabunny Output — MP4 (H.264 + AAC). fastStart: 'in-memory'
            //    moov atom'u dosya başına yazar → streamable dosya,
            //    Instagram/QuickTime/önizleme dostu.
            output = new Output({
                format: new Mp4OutputFormat({ fastStart: 'in-memory' }),
                target: new BufferTarget(),
            });

            // Video: CanvasSource her add() çağrısında canvas'ın o anki
            // içeriğini WebCodecs VideoEncoder'a (H.264) verir.
            // keyFrameInterval: 2sn → seek/önizleme dostu.
            canvasSource = new CanvasSource(canvas, {
                codec: 'avc',
                quality: new Quality({ bitrate: 2500000 }),
                keyFrameInterval: 2,
            });
            output.addVideoTrack(canvasSource, { frameRate: 30 });

            // Ses: offline mix buffer'ı tek seferde AAC'ye kodla. mixBuffer
            // null ise (hiç ses kaynağı yok) ses track'siz devam edilir.
            if (mixBuffer) {
                audioSource = new AudioBufferSource({
                    codec: 'aac',
                    quality: new Quality({ bitrate: 128000 }),
                });
                output.addAudioTrack(audioSource);
            }

            await output.start();

            // Ses track'ini hemen besle (offline buffer) — video döngüsüyle
            // paralel ilerler, video 'ended' olduğunda çoktan bitmiş olur.
            const audioPromise = mixBuffer && audioSource
                ? audioSource.add(mixBuffer)
                : Promise.resolve();

            // Overlay layout'larını (konum, font boyutu, satır bölme) BİR KERE
            // Overlay'lar Gemini stiliyle çizilir — her karede doğrudan this.overlays kullanılır.
            const subtitleLineCache = new Map();

            // 5) Frame çizme + encode döngüsü
            let lastCurrentTime = -1;
            let lastAdvanceAt = performance.now();
            let lastAddedTime = -1;
            let loopError = null;
            let encodedFrames = 0;
            let fpsWindowStart = performance.now();
            const drawFrame = async (mediaTime) => {
                // readyState < 2 (HAVE_CURRENT_DATA): video'nun o an
                // gösterilecek gerçek bir karesi yok — eski kareyi tekrar
                // encoder'a göndermek yerine hiçbir şey yapma.
                if (video.readyState < 2) return;
                const t = mediaTime ?? video.currentTime;
                // Timestamp monotonik olmalı: aynı kare iki kez sunulursa
                // (seek/pause kenar durumları) sessizce atla.
                if (t <= lastAddedTime) return;
                ctx.drawImage(video, 0, 0, width, height);
                drawFrameOverlays(ctx, this.overlays, t, width, height);
                if (burn && hasSegments) {
                    const subtitle = getActiveSubtitle(this.segments, t);
                    if (subtitle) drawSubtitleToCanvas(ctx, subtitle, width, height, subtitleLineCache);
                }
                // Timestamp olarak mediaTime (saniye) kullan — frame sayacı ya
                // da sabit aralık YOK; VFR kaynaklar bile doğru mux'lanır.
                // await: encoder geri basıncı doğal şekilde döngüye yansır.
                lastAddedTime = t;
                await canvasSource.add(t);
                // Encode hızı: 1 saniyelik pencerede eklenen kare sayısı.
                // Kullanıcı "yavaş ama akıcı" ile "takıldı" ayrımını görsün;
                // ayrı reaktif alanda tutulur (her frame'de status string'i
                // güncellemek yerine), pencere kapanınca en fazla 1/sn yazılır.
                encodedFrames++;
                const nowMs = performance.now();
                if (nowMs - fpsWindowStart >= 1000) {
                    this.encodeFps = Math.round((encodedFrames * 1000) / (nowMs - fpsWindowStart));
                    encodedFrames = 0;
                    fpsWindowStart = nowMs;
                }
                if (duration > 0) {
                    this.progress = Math.min(95, 5 + Math.round((t / duration) * 90));
                }
                if (t > lastCurrentTime) {
                    lastCurrentTime = t;
                    lastAdvanceAt = performance.now();
                }
            };

            // requestVideoFrameCallback varsa onu kullan (daha verimli, sadece
            // yeni frame geldiğinde tetiklenir); yoksa requestAnimationFrame.
            let rafId = null;
            let rvfcId = null;
            let stopped = false;

            const scheduleFrame = () => {
                if (stopped) return;
                if (typeof video.requestVideoFrameCallback === 'function') {
                    rvfcId = video.requestVideoFrameCallback(async (_now, metadata) => {
                        try {
                            await drawFrame(metadata?.mediaTime);
                        } catch (e) {
                            loopError = e; // encoder hatası → finalize'da yüzeye çıkar
                            stopped = true;
                            return;
                        }
                        scheduleFrame();
                    });
                } else {
                    const loop = () => {
                        if (stopped) return;
                        drawFrame()
                            .then(() => { rafId = requestAnimationFrame(loop); })
                            .catch((e) => { loopError = e; stopped = true; });
                    };
                    rafId = requestAnimationFrame(loop);
                }
            };

            // 6) Bitirme — Mediabunny finalize kaynakları kapatıp MP4
            //    konteynerini yazar. 'ended' gelmezse (arka planda takılma)
            //    stallInterval eldeki veriyle finalize eder.
            let finalized = false;
            let resolveRecording, rejectRecording;
            const recordingDone = new Promise((resolve, reject) => {
                resolveRecording = resolve;
                rejectRecording = reject;
            });
            const finalizeOutput = async () => {
                if (finalized) return;
                finalized = true;
                stopped = true;
                try {
                    // Önce ses add()'ının bitmesini bekle — kaynağı add
                    // in-flight'ken kapatmak yarış koşulu yaratır.
                    await audioPromise;
                    try { canvasSource?.close(); } catch (e) {}
                    try { audioSource?.close(); } catch (e) {}
                    await output.finalize();
                    resolveRecording(new Blob([output.target.buffer], { type: 'video/mp4' }));
                } catch (err) {
                    rejectRecording(loopError || err);
                }
            };

            video.addEventListener('ended', () => { finalizeOutput(); }, { once: true });

            // KÖK NEDEN (85%'te takılma) — oynatma takılması izleyicisi:
            // Sekme arka plana alındığında rVFC/rAF throttle/pause edilebilir,
            // video decode durabilir ve bu durumda 'ended' event'i HİÇ gelmez
            // — işlem süresiz beklemede kalırdı. setInterval, rAF'in aksine
            // arka planda da (en fazla ~1sn'ye kadar throttle ile) çalışmaya
            // devam ettiği için buradaki takılmayı güvenilir şekilde yakalar.
            // NOT: Bu, WebCodecs encode'a geçmekle çözülmedi — video decode
            // hâlâ realtime. Kalıcı çözüm hybrid decode (bkz. dosya başı).
            const STALL_TIMEOUT_MS = 5000;
            const stallInterval = setInterval(() => {
                if (stopped) return;
                if (performance.now() - lastAdvanceAt > STALL_TIMEOUT_MS) {
                    console.warn('Video oynatma takıldı (muhtemelen sekme arka planda) — kayıt eldeki veriyle sonlandırılıyor.');
                    this.status = 'Uyarı: Oynatma yavaşladı, kayıt eldeki veriyle tamamlanıyor…';
                    finalizeOutput();
                }
            }, 1000);

            // Kullanıcı sekmeyi arka plana alırsa bilgilendir — tarayıcılar
            // arka plan sekmelerinde rVFC/rAF'i throttle eder, bu da kaydın
            // yavaşlamasına/kısalmasına yol açabilir.
            const onVisibilityChange = () => {
                if (document.hidden && !stopped) {
                    console.warn('Sekme arka plana alındı — kayıt yavaşlayabilir. İşlem bitene kadar sekmeyi ön planda tutmanız önerilir.');
                }
            };
            document.addEventListener('visibilitychange', onVisibilityChange);

            // Cleanup listesi
            this._cleanupFns.push(() => {
                stopped = true;
                if (rafId) cancelAnimationFrame(rafId);
                if (rvfcId && typeof video.cancelVideoFrameCallback === 'function') {
                    try { video.cancelVideoFrameCallback(rvfcId); } catch (e) {}
                }
                clearInterval(stallInterval);
                document.removeEventListener('visibilitychange', onVisibilityChange);
                try { canvasSource?.close(); } catch (e) {}
                try { audioSource?.close(); } catch (e) {}
            });

            // 7) Başlat — encode realtime'dır (video oynatma hızıyla ilerler).
            this.status = burn
                ? 'Video yazıyla yeniden kodlanıyor…'
                : (dubAudio ? 'Video + ses birleştiriliyor…' : 'Video kopyalanıyor…');

            // İlk kareyi oynatma başlamadan timestamp 0 ile eklemeyi dene
            // (readyState hazırsa eklenir; değilse rVFC'nin ilk karesiyle başlar).
            await drawFrame(0);

            // Videoyu oynat + frame çizmeye başla
            try {
                await video.play();
            } catch (e) {
                throw new Error('Video oynatılamadı (autoplay engeli?): ' + e.message);
            }
            scheduleFrame();

            // 8) Bitene kadar bekle
            const blob = await recordingDone;

            // 9) Sonuç
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
            this.encodeFps = null;
            this.busy = false;
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