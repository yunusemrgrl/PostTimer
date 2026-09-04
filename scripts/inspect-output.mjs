/**
 * dub-output.mp4 doğrulaması: konteyner, süre, video+ses track'leri.
 * Kullanım: node scripts/inspect-output.mjs
 */
import { Input, ALL_FORMATS, BlobSource } from 'mediabunny';
import { readFileSync } from 'node:fs';

const input = new Input({
    source: new BlobSource(new Blob([readFileSync('storage/app/dub-output.mp4')])),
    formats: ALL_FORMATS,
});

const format = await input.getFormat();
const video = await input.getPrimaryVideoTrack();
const audio = await input.getPrimaryAudioTrack();

console.log(JSON.stringify({
    format: format?.name ?? String(format),
    mimeType: await input.getMimeType(),
    durationSec: await input.computeDuration(),
    video: video
        ? { codec: video.codec, w: await video.getPixelWidth?.(), h: await video.getPixelHeight?.() }
        : null,
    audio: audio
        ? { codec: audio.codec, channels: audio.numberOfChannels, sampleRate: audio.sampleRate }
        : null,
}, null, 2));
