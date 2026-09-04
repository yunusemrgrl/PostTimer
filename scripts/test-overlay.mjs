/**
 * Overlay hizalama testi: gerçek Gemini bbox + Türkçe metin ile bulut çizer.
 * Tarayıcı canvas'ında çizip PNG olarak kaydeder.
 * Kullanım: node scripts/test-overlay.mjs
 */
import { chromium } from 'playwright';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });

const overlays = [
    { text: 'The best Christmas gift for book lovers in 2026', translation: "2026'da kitap severler için en iyi Yılbaşı hediyesi", bbox: { left: 5, top: 17, width: 90, height: 11 } },
    { text: "Comment 'book' if u Want one", translation: "İstiyorsan yorumlara 'kitap' yaz", bbox: { left: 10, top: 22, width: 80, height: 11 } },
];

await page.setContent(`<canvas id="c" width="1080" height="1920"></canvas>`);

await page.evaluate(({ overlays }) => {
    const canvas = document.getElementById('c');
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;

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

    function computeOverlayLayout(ctx, overlay, videoWidth, videoHeight) {
        const b = overlay.bbox || {};
        const padX = videoWidth * 0.04;
        const padY = videoHeight * 0.025;
        const x = Math.max(0, (b.left / 100) * videoWidth - padX);
        const y = Math.max(0, (b.top / 100) * videoHeight - padY);
        const boxW = Math.min(videoWidth - x, ((b.width / 100) * videoWidth) + padX * 2.5);
        const boxH = Math.min(videoHeight - y, ((b.height / 100) * videoHeight) + padY * 2.5);
        if (boxW < videoWidth * 0.05 || boxH < videoHeight * 0.015) return null;
        const radius = Math.min(boxH * 0.2, 16);
        const innerW = boxW * 0.9;
        const innerH = boxH * 0.82;
        let fontSize = Math.min(innerH * 0.5, videoHeight * 0.04);
        let lineHeight = fontSize * 1.3;
        ctx.font = `bold ${Math.round(fontSize)}px sans-serif`;
        let lines = wrapLines(ctx, overlay.translation, innerW);
        while (lines.length * lineHeight > innerH && fontSize > videoHeight * 0.012) {
            fontSize -= Math.max(1, Math.round(fontSize * 0.06));
            ctx.font = `bold ${Math.round(fontSize)}px sans-serif`;
            lines = wrapLines(ctx, overlay.translation, innerW);
            lineHeight = fontSize * 1.3;
        }
        fontSize = Math.round(fontSize);
        const centerX = x + boxW / 2;
        const totalHeight = lines.length * lineHeight;
        const firstLineY = y + (boxH - totalHeight) / 2 + lineHeight / 2;
        return { x, y, boxW, boxH, radius, font: `bold ${fontSize}px sans-serif`, lines, lineHeight, centerX, firstLineY, shadowBlur: Math.round(videoHeight * 0.006) };
    }

    function drawOverlay(ctx, layout) {
        const { x, y, boxW, boxH, radius, font, lines, lineHeight, centerX, firstLineY, shadowBlur } = layout;
        ctx.save();
        ctx.beginPath();
        ctx.roundRect(x, y, boxW, boxH, radius);
        ctx.fillStyle = 'rgba(255, 255, 255, 1)';
        ctx.shadowColor = 'rgba(0, 0, 0, 0.25)';
        ctx.shadowBlur = shadowBlur;
        ctx.fill();
        ctx.shadowBlur = 0;
        ctx.strokeStyle = 'rgba(255, 255, 255, 1)';
        ctx.lineWidth = 2;
        ctx.stroke();
        ctx.fillStyle = 'rgba(17, 17, 17, 1)';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = font;
        let lineY = firstLineY;
        for (const line of lines) {
            ctx.fillText(line, centerX, lineY);
            lineY += lineHeight;
        }
        ctx.restore();
    }

    // Arka plan (simüle video karesi)
    ctx.fillStyle = '#1a1a2e';
    ctx.fillRect(0, 0, W, H);

    // Orijinal İngilizce metni çiz (beyaz) — "orijinal"ı simüle
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 56px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (const ov of overlays) {
        const b = ov.bbox;
        ctx.fillText(ov.text, (b.left + b.width / 2) / 100 * W, (b.top + b.height / 2) / 100 * H);
    }

    // Türkçe overlay'ları çiz
    for (const ov of overlays) {
        const layout = computeOverlayLayout(ctx, ov, W, H);
        if (layout) drawOverlay(ctx, layout);
    }
}, { overlays });

await page.screenshot({ path: 'storage/app/overlay-test.png' });
console.log('saved: storage/app/overlay-test.png');
await browser.close();
