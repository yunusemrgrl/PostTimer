# scripts/ — Dublaj Smoke Test Altyapısı

`dubCombiner` (Önizle/İndir) butonlarının **uçtan uca** çalıştığını gerçek
tarayıcıda kanıtlayan Playwright tabanlı smoke test. 2026-09'da "butonlar hiç
aksiyon almıyor" şikâyetinin teşhisinde kullanıldı; hem buton bağlamasını hem
tam MP4 pipeline'ını doğruladı, ayrıca gerçek bir bug (videoBlob TDZ → sessiz
çıktı) yakaladı.

## Dosyalar

| Dosya | Görev |
|---|---|
| `dub-button-smoke.mjs` | login → İçerikler → ⋯ → "Dublaj Durumu" slide-over → "Dublajlı Sonuç" sekmesi → **Önizle** tıkla; `status`/`busy`/`progress`/`encodeFps` izler, console + pageerror + network yakalar, çıktıyı `storage/app/dub-output.mp4`'e kaydeder |
| `inspect-output.mjs` | `dub-output.mp4`'ü mediabunny ile doğrular: konteyner, süre, video (avc) + ses (AAC) track varlığı |

## Hızlı başlangıç

```bash
# 1) Smoke kullanıcısı (bir kez — super_admin, tüm tenant'lara erişir)
php artisan db:seed --class=PlaywrightSmokeUserSeeder

# 2) Uygulamayı secure context'te ayağa kaldır (localhost = secure)
php artisan serve --host=127.0.0.1 --port=8000

# 3) Test (başarılı çıktıyı storage/app/dub-output.mp4'e kaydeder)
npm run smoke:dub

# 4) Çıktı doğrulaması (MP4 + avc + AAC track)
npm run smoke:dub:inspect
```

Varsayılan hedef `https://multitenant-app.test` (Herd + `herd secure`). Farklı ortam:
`node scripts/dub-button-smoke.mjs --base=http://127.0.0.1:8000`

> **R2 CDN notu:** Gerçek CDN videosu (media.posttimer...) yalnızca
> `http://multitenant-app.test` origin'ine CORS izni veriyor; `https://`
> origin'i henüz eklenmediyse fetch "Failed to fetch" verir. https üzerinden
> tam uçtan uca test için Cloudflare R2 CORS kuralına `https://multitenant-app.test`
> eklenmeli (bkz. .ai/rules/app.md). Eklenene kadar aynı-origin test videosu veya
> localhost kullanın.

Kimlik bilgileri: `PW_SMOKE_EMAIL` / `PW_SMOKE_PASSWORD` env'leri veya
`--email=` / `--password=` argümanları (seeder ile eşleşmeli).

## Bayraklar

- `--channel=msedge|chrome` — **encode testi için ŞART**. Playwright bundle
  chromium'unda proprietary codec (H.264/AAC) yoktur; gate fail-fast yapar.
  Ayrıca eski headless modda WebCodecs hiç yoktur → `--headed` kullan.
- `--full` — pipeline bitene kadar bekle (realtime: video süresi kadar sürer).
- `--headed` / `--base=` / `--path=` / `--email=` / `--password=`

## Bilinen tuzaklar (bkz. .ai/rules/app.md)

- **Secure context:** WebCodecs yalnızca HTTPS/localhost'ta tanımlıdır.
  `http://multitenant-app.test` insecure'dur → butonlar çalışır ama gate
  "WebCodecs desteklemiyor" der. Çözüm: localhost veya `herd secure`.
- **R2 CORS:** CDN sadece `http://multitenant-app.test` origin'ine izin verir.
  localhost'tan gerçek CDN videosu çekilemez — ya R2 CORS'a origin ekleyin ya
  test videosunu same-origin servis edin.
- Çıktının ses track'i yoksa önce `videoBlob` scope'unu kontrol edin (yakalanan
  ve düzeltilen bug tam olarak buydu).

## Geliştirme yol haritası

1. **İndir butonu assertion'ı** — `page.waitForEvent('download')` ile İndir
   akışını da doğrula (şu an yalnızca Önizle).
2. **Dub-audio path** — `audioUrl`'li (Seslendirme tamamlanmış) bir kayıtta
   mix'li çıktının AAC'sini inspector ile doğrula (offline mix pipeline'ı).
3. **Stall-recovery otomasyonu** — CDP `Emulation.setFocusEmulationEnabled` /
   yeni sekme açarak arka plana alma; kesilmiş MP4'ün finalize edildiğini
   doğrula (Chrome + VLC manuel kontrolü yerine otomatik `inspect-output`).
4. **VFR fixture** — değişken kare hızlı kaynak üretip QuickTime-uyumlu
   timestamp doğrulaması (bkz. .ai/rules smoke-teşhisi bölümü).
5. **Bellek telemetrisi** — `performance.memory` örneklemesi ile uzun videoda
   BufferTarget büyüme grafiği (StreamTarget geçiş kararı için veri).
6. **CI** — GitHub Actions: Windows/Ubuntu runner + `--channel=chrome` +
   `dub-output.mp4` artifact upload (Laravel Cloud serverless'ta local FFmpeg
   yok; bu test tek doğrulama katmanı).
