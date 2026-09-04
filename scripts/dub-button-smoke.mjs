/**
 * Playwright smoke test: dubCombiner Önizle/İndir butonları aksiyon alıyor mu?
 *
 * Akış: login (super_admin) → İçerikler → satır ⋯ menüsü → "Dublaj Durumu"
 * slide-over → "Önizle" tıkla → status metnini ve konsolu izle.
 *
 * Çalıştırma: node scripts/dub-button-smoke.mjs [--channel=msedge] [--full]
 *   --channel=msedge|chrome  Sistem tarayıcısı kullan (H.264/AAC encode var).
 *   --full                   Gerçek CDN videosu olan kayıtta tam pipeline bekle.
 */
import { chromium } from 'playwright';

const args = process.argv.slice(2);
const channel = args.find((a) => a.startsWith('--channel='))?.split('=')[1] || null;
const full = args.includes('--full');
const headed = args.includes('--headed');
const BASE = args.find((a) => a.startsWith('--base='))?.split('=')[1] || 'http://multitenant-app.test';
const PATH = args.find((a) => a.startsWith('--path='))?.split('=')[1] || null;
const EMAIL = 'pw-smoke@example.com';
const PASSWORD = 'password';

const consoleLines = [];
const pageErrors = [];
const failedRequests = [];

const browser = await chromium.launch({
    headless: !headed,
    ...(channel ? { channel } : {}),
});
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

page.on('console', (msg) => consoleLines.push(`[console.${msg.type()}] ${msg.text()}`));
page.on('pageerror', (err) => pageErrors.push(`[pageerror] ${err.message}`));
page.on('requestfailed', (req) => {
    failedRequests.push(`[requestfailed] ${req.method()} ${req.url()} — ${req.failure()?.errorText}`);
});
page.on('response', (res) => {
    if (res.status() >= 400 && !res.url().includes('/livewire/')) {
        failedRequests.push(`[http ${res.status()}] ${res.url()}`);
    }
});

// 1) Login
await page.goto(`${BASE}/app/login`, { waitUntil: 'networkidle' });
await page.fill('input[type="email"]', EMAIL);
await page.fill('input[type="password"]', PASSWORD);
await page.click('button[type="submit"]');
await page.waitForLoadState('networkidle');
console.log(`LOGIN OK → ${page.url()}`);

// 2) video-dub script'i yüklendi mi?
const dubLoaded = await page.evaluate(() =>
    performance.getEntriesByType('resource').some((e) => e.name.includes('video-dub')),
);
console.log(`video-dub.js loaded: ${dubLoaded}`);
const ctx = await page.evaluate(() => ({
    isSecureContext: window.isSecureContext,
    VideoEncoder: typeof VideoEncoder,
    AudioEncoder: typeof AudioEncoder,
}));
console.log(`secure context: ${JSON.stringify(ctx)}`);

// 3) İçerikler sayfasına git: --path verilmişse direkt, yoksa nav linkiyle
if (PATH) {
    await page.goto(`${BASE}${PATH}`, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(1500);
} else {
    await page.getByRole('link', { name: 'İçerikler' }).first().click();
    await page.waitForLoadState('networkidle');
}
console.log(`CONTENTS → ${page.url()}`);

// 4) "İşlemler" (⋯) trigger'larını sırayla aç; "Dublaj Durumu" olanı bul
const triggers = page.locator('button[aria-label="İşlemler"], button[title="İşlemler"], [x-tooltip*="İşlemler"]');
const count = await triggers.count();
console.log(`action triggers: ${count}`);

let opened = false;
for (let i = 0; i < count; i++) {
    await triggers.nth(i).click();
    await page.waitForTimeout(400);
    const item = page.getByRole('menuitem', { name: /Dublaj Durumu/i })
        .or(page.locator('button, a').filter({ hasText: 'Dublaj Durumu' }).first());
    if (await item.first().isVisible().catch(() => false)) {
        await item.first().click();
        opened = true;
        break;
    }
    await page.keyboard.press('Escape');
    await page.waitForTimeout(200);
}
if (!opened) {
    console.log('FAIL: "Dublaj Durumu" aksiyonu hiçbir satırda bulunamadı.');
} else {
    await page.waitForTimeout(800);

    // 5a) Slide-over'da "Dublajlı Sonuç" sekmesini aç (Önizle/İndir butonları
    //     x-show="showDubbed" arkasında gizli).
    const dubTab = page.locator('button').filter({ hasText: 'Dublajlı Sonuç' }).first();
    if (await dubTab.isVisible().catch(() => false)) {
        await dubTab.click();
        await page.waitForTimeout(300);
    }

    // 5b) dubCombiner Alpine scope'u bağlandı mı?
    const scope = await page.evaluate(() => {
        const el = document.querySelector('[x-data*="dubCombiner"]');
        if (!el) return { found: false };
        const data = el._x_dataStack?.[0];
        return {
            found: true,
            wired: !!data,
            status: data?.status ?? null,
            busy: data?.busy ?? null,
            previewUrl: data?.previewUrl ?? null,
        };
    });
    console.log(`dubCombiner scope: ${JSON.stringify(scope)}`);

    // 6) ÖNİZLE'ye tıkla ve status'ü izle
    const preview = page.getByRole('button', { name: /Önizle/ }).first();
    try {
        await preview.waitFor({ state: 'visible', timeout: 10_000 });
    } catch {
        console.log('FAIL: Önizle butonu modalda görünmüyor.');
        await page.screenshot({ path: 'storage/app/dub-smoke-fail.png' });
    }
    if (await preview.isVisible().catch(() => false)) {
        const before = await preview.evaluate((el) => el.disabled);
        const statusBefore = await page.evaluate(() => {
            const el = document.querySelector('[x-data*="dubCombiner"]');
            return el?._x_dataStack?.[0]?.status ?? null;
        });
        await preview.click();

        const samples = [];
        const deadline = Date.now() + (full ? 150_000 : 20_000);
        while (Date.now() < deadline) {
            await page.waitForTimeout(1000);
            const s = await page.evaluate(() => {
                const el = document.querySelector('[x-data*="dubCombiner"]');
                const d = el?._x_dataStack?.[0];
                return {
                    status: d?.status ?? null,
                    busy: d?.busy ?? null,
                    progress: d?.progress ?? null,
                    encodeFps: d?.encodeFps ?? null,
                    previewUrl: d?.previewUrl ?? null,
                };
            });
            samples.push(s);
            console.log(`t+${samples.length}s status="${s.status}" busy=${s.busy} progress=${s.progress} fps=${s.encodeFps}`);
            if (s.busy === false && samples.length > 2) break;
        }

        const after = await preview.evaluate((el) => el.disabled);
        const acted = samples.some((s) => s.busy === true || (s.status && s.status !== statusBefore));
        console.log('--- RESULT ---');
        console.log(`button disabled before=${before} after=${after}`);
        console.log(`button ACTED (status/busy değişti): ${acted}`);
        console.log(`final status: "${samples.at(-1)?.status}"`);
        console.log(`previewUrl set: ${samples.at(-1)?.previewUrl ? 'YES' : 'no'}`);

        // Çıktıyı diske kaydet (Node tarafında mediabunny ile doğrulanacak)
        if (samples.at(-1)?.previewUrl) {
            const b64 = await page.evaluate(async () => {
                const el = document.querySelector('[x-data*="dubCombiner"]');
                const url = el?._x_dataStack?.[0]?.previewUrl;
                const buf = await (await fetch(url)).arrayBuffer();
                let binary = '';
                const bytes = new Uint8Array(buf);
                for (let i = 0; i < bytes.length; i += 0x8000) {
                    binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
                }
                return btoa(binary);
            });
            const { writeFileSync } = await import('node:fs');
            writeFileSync('storage/app/dub-output.mp4', Buffer.from(b64, 'base64'));
            console.log('output saved: storage/app/dub-output.mp4');
        }

        await page.screenshot({ path: 'storage/app/dub-smoke.png' });
        console.log('screenshot: storage/app/dub-smoke.png');
    }
}

console.log('--- console (son 20) ---');
for (const line of consoleLines.slice(-20)) console.log(line);
console.log('--- pageerrors ---');
for (const line of pageErrors) console.log(line);
console.log('--- failed requests (ilk 10) ---');
for (const line of failedRequests.slice(0, 10)) console.log(line);

await browser.close();
