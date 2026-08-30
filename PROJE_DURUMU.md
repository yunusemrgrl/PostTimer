# PostTimer — Proje Durumu (2026-08-29)

Çok kiracılı (multi-tenant) Filament + Laravel 13 uygulaması. Video içeriğini
yapay zeka ile Türkçeye çevirir, seslendirir ve dublajlı video üretir.

## Mimari

```
app/
├── Domain/
│   ├── Video/           ← AI video yerelleştirme (yeni)
│   │   ├── Enums/       LocalizationStatus, LocalizationLanguage
│   │   ├── Services/     GeminiVideoTranslationService, ElevenLabsTtsService
│   │   └── Contracts/   VideoTranslationProvider, TextToSpeechProvider (Strategy)
│   ├── Instagram/       Instagram yayın/hesap/insight servisleri
│   ├── Publishing/      Yayın, toplu içerik içe aktarma, akış loglama
│   ├── Notification/    Telegram bildirim merkezi (event-driven)
│   └── Stock/           Amazon ürün/stok parser
├── Events/              LocalizationAnalyzed, LocalizationVoiceCompleted
├── Listeners/           AI event'lerini dinleyip Telegram bildirimi gönderir
├── Jobs/                LocalizeVideoJob, GenerateVideoVoiceJob (queued)
├── Models/              VideoLocalization (state machine + optimistic lock)
└── Support/Http/        AbstractExternalApiClient (Template Method)
```

Servisler `app/Services/` altından domain klasörlerine taşındı. Tüm FQCN
referansları güncellendi; `AbstractExternalApiClient` artık `Support/Http/`
altında domain-özerk HTTP altyapısı.

## AI Dublaj Akışı (Filament panelinde, uçtan uca)

```
[AI Çeviri] → LocalizeVideoJob (queue) → Gemini: video analizi + timestamp'li
              Türkçe çeviri → "Çevrildi" badge (optimistic, 10s polling)

[Yerelleştirme Sonucu] → Modal: segmentler, ekrandaki yazılar, script,
                         Türkçe ses önizleme, altyazı toggle

[Türkçe Seslendir] → GenerateVideoVoiceJob (queue) → ElevenLabs TTS (flash
                     model, ucuz) → MP3 R2'ye → "Ses Hazır" badge

[Dublajlı Videoyu İndir] → ffmpeg.wasm (tarayıcıda, serverless-uyumlu):
                           video + ses mux, isteğe bağlı SRT altyazı burn-in.
```

## Güvenlik & Üretime Hazırlık

- **İdempotency:** Çift dispatch'te tekrar ücretli Gemini/TTS çağrısı yok
  (status guard + ShouldBeUnique job kilidi).
- **State machine:** `LocalizationStatus::canTransitionTo()` + optimistic
  lock (`UPDATE ... WHERE status=current`, 0 satır = exception).
- **Maliyet:** ElevenLabs varsayılan model `eleven_flash_v2_5` (multilingual
  ~5x ucuz); output `mp3_22050_64` düşük bitrate.
- **Domain event'ler:** Bildirimler servisten ayrıldı — `LocalizationAnalyzed`
  / `LocalizationVoiceCompleted` event'leri listener'lar aracılığıyla
  Telegram'a iletiliyor (auto-discovery).
- **Laravel Cloud (serverless):** FFmpeg sunucuya kurulamayacağı için video
  birleştirme `ffmpeg.wasm` ile **tarayıcıda** yapılır — sunucu yüzeyi yok.

## Kurallar (.ai/rules)

`services.md`: servisler `app/Domain/**/Services/` altında; yeni düz
`app/Services` sınıfi yasak. `app.md`: AI maliyet konvansiyonu. `tests.md`:
test yazmama tercihi (kullanıcı istemediği sürece).

## Doğrulama

```
php artisan test --compact  →  199 passed (698 assertions)
npm run build               →  video-dub.js + ffmpeg worker üretildi
vendor/bin/pint             →  temiz
```

## Bilinçli açık bırakılanlar

- `telegram/webhook` throttle'sız + CSRF'muaf (brute-force/spam riski —
  `throttle:60,1` eklenmeli).
- `ProductFactory` ASIN (varchar(10) kolona 11 karakter) — truncate hatası,
  düzeltilmesi gerekir.
- Altyazı stili (font/boyut/pozisyon) — ffmpeg `force_style` ile
  özelleştirilebilir, şimdilik varsayılan.
