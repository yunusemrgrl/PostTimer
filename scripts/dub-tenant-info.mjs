/**
 * Tenant onboarding / içerik sayfası teşhisi.
 * Kullanım: node scripts/dub-tenant-info.mjs --email=owner@example.com --password=password123
 */
import { chromium } from 'playwright';
const args = process.argv.slice(2);
const BASE = args.find((a) => a.startsWith('--base='))?.split('=')[1] || 'http://multitenant-app.test';
const EMAIL = args.find((a) => a.startsWith('--email='))?.split('=')[1] || 'owner@example.com';
const PASSWORD = args.find((a) => a.startsWith('--password='))?.split('=')[1] || 'password';

const browser = await chromium.launch({ headless: true, channel: 'msedge' });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

await page.goto(`${BASE}/app/login`, { waitUntil: 'networkidle' });
await page.fill('input[type="email"]', EMAIL);
await page.fill('input[type="password"]', PASSWORD);
await page.click('button[type="submit"]');
await page.waitForTimeout(2500);
console.log('after login ->', page.url());

// Hata mesajı / uyarı görünüyor mu?
const loginAlert = await page.evaluate(() => {
    const a = document.querySelector('[role="alert"], .fi-fo-field-wrp-validation-message, .text-danger-600, .fi-notification');
    return a ? (a.textContent || '').trim().slice(0, 200) : null;
});
console.log('login alert:', loginAlert);

// Panel köküne git (tenant seçim ekranı olabilir)
await page.goto(`${BASE}/app`, { waitUntil: 'domcontentloaded' });
await page.waitForLoadState('networkidle').catch(() => {});
await page.waitForTimeout(1500);
console.log('after /app ->', page.url());

// Sayfadaki tüm linkleri ve buton metinlerini dök
const refs = await page.evaluate(() => Array.from(document.querySelectorAll('a, button'))
    .map((el) => ({
        tag: el.tagName,
        text: (el.textContent || '').trim().slice(0, 60),
        href: el.getAttribute('href') || '',
    }))
    .filter((x) => x.text || x.href)
    .slice(0, 60));
console.log('refs:', JSON.stringify(refs, null, 1));

await page.screenshot({ path: 'storage/app/dub-tenant.png' });
console.log('screenshot: storage/app/dub-tenant.png');
await browser.close();