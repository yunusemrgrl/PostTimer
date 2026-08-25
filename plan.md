# PostTimer — Devam Planı

> Bu dosya, TryPost karar raporu sonrası kalan çalışma kalemlerini ve
> implementasyon sırasını belgeler. Her madde tamamlandıkça işaretlenir.

## Durum Özeti

Tamamlanan büyük işler (commit'lerde):
- `9b4e663` P0 dayanıklılık: recover-stuck, exponential backoff (+kota kontrolü zaten mevcuttu)
- `f27c44c` `instagram:legacy-migrate` komutu (dry-run / --force)
- `1f83c62` Legacy InstagramPost publish domain emeklisi (−3000 satır)
- `b6b25a1` `(status, scheduled_at)` scheduler indeksi
- Ayrıca: lock'lu token refresh, carousel checkpoint, yayın-öncesi health check,
  error categorization, Curator magic-byte doğrulama

## Yapılacaklar

### [x] V1 — Curator video thumbnail sağlamlaştırma
Sahip: MediaObserver::generateVideoThumbnail
- [x] `ffmpegAvailable()` — Cache (1 gün) + `where/which ffmpeg`; yoksa
      `curations.thumbnail_status = 'ffmpeg_missing'`
- [x] Thumbnail boyut > 0 doğrulaması; hata durumunda
      `curations.thumbnail_status = 'failed'` + mesaj
- [x] Başarıda `thumbnail_status = 'ok'`
- [x] Neden `thumbnailUrl()` null döndü artık sorgulanabilir
- [x] Testler: VideoThumbnailRobustnessTest (ffmpeg-yok, cache'li probe, ffmpeg-hatası)

### [x] O1 — Scheduler gözlemlenebilirliği
- [x] Üç yayın komutuna `onSuccess`/`onFailure` → Cache `sched:last-run:{cmd}` (TTL 2 gün)
- [x] `onFailure` → Telegram tek satır uyarı (notifyTeams)
- [x] Yeni `publications:health`: takılı publishing sayısı, 24 saatlik yayın planı,
      token süresi yaklaşan hesaplar, son çalışma durumları; sorun varsa FAILURE
- [x] Testler: PublicationsHealthTest (healthy/fail-stuck/fail-last-run/cache/output)

### Sonraki adaylar (henüz planlanmadı)
- [x] MCP tool seti: schedule_publication, get_publication_status,
      create_content, list_publications (commit'ler d2dc81f + bu tur;
      endpoint `/mcp/publications`; production öncesi auth EKLENMELİ)
- [ ] MCP tool testleri (yolo fazı sonrası dengelemede)
- [x] URL-import medya feature'ı — SafeHttpFetcher (SSRF guard) +
      MediaUrlImporter + `import_media_from_url` MCP tool'u (commit dcbdb38)
- [ ] Horizon değerlendirmesi (yük kanıtlandığında)

### [x] O2 — MCP endpoint auth (production ön şartı)
- [x] `EnsureMcpToken` middleware — `MCP_TOKEN` env set ise `X-Mcp-Token`
      zorunlu (hash_equals); boşsa local serbest
- [x] Manuel doğrulama: tokensiz 401 / yanlış 401 / doğru 200

### [x] O3 — Manuel doğrulama turu (tinker/artisan, gerçek çalışma)
- [x] schedule_publication uçtan uca → Publication scheduled + job DB queue'da
- [x] recover-stuck → takılı yayın FAILED
- [x] publications:health → stuck>0 iken FAILURE
- [x] legacy-migrate guard → dolu tabloda reddediyor (doğru)

### [x] Horizon kararı: ŞİMDİLİK GEREK YOK
Queue sürücümüz `database`; Horizon yalnızca Redis'te çalışır. Tek sunucu +
düşük job hacmi için database queue + supervisor yeterli. Redis'e geçilirse
(yük/çok worker) tekrar değerlendirilecek.

## Gerçek Reel Yayını — Uçtan Uca Doğrulama (yolo turu 5)

### Form karşılaştırması (ContentForm vs eski InstagramPostForm)
CuratorPicker kullanımı BİREBİR AYNI desen (afterStateHydrated ile URL→medya
geri yükleme, dehydrateStateUsing ile Media::resolveUrl → public URL).
VIDEO tipinde acceptedFileTypes video/* ✓. Kod değişikliği GEREKMEDİ.

### Zincir doğrulaması (sahte Graph API ile gerçek akış)
`_e2e_reel.php` simülasyonu (scheduler komutu + job handle + Http::fake):
- Content(VIDEO+REELS) → InstagramMediaFactory → **ReelMedia** ✓
- REELS container payload `video_url` = content.media_url =
  **R2 public r2.dev URL** ✓ (CURATOR_DEFAULT_DISK=r2, R2_URL set)
- Container status poll: FINISHED döndüğünde media_publish'e geçiyor ✓
- Sonuç: status=**published**, container_id/media_id/permalink dolu ✓
- Worker transport: `queue:work --once` gerçek PublishScheduledPublication'ı
  RUNNING→DONE çalıştırdı; boş kuyrukta closure job da işlendi (PASS) ✓

### Düzeltilen blocking bug (önceki tur, 95155fa)
Job timeout 85sn < video poll bütçesi ~300sn → video yayını kesiliyordu.
timeout=420, uniqueFor=1800, queue retry_after=900.

## GERÇEK Reel Yayın Testi — Runbook

Ön şartlar:
1. `.env`: **R2_URL bir CUSTOM DOMAIN olmalı** (örn. `https://media.postimer.com`),
   ASLA `.r2.dev` test alan adı. r2.dev yönetilen public adresi variabil
   rate-limit + bant genişliği throttle uygular; büyük videoyu hem tarayıcı
   (Range/seek) hem Instagram Graph API indirmede (`waitForContainerToFinish`
   IN_PROGRESS'ta kilitleyebilir) takıyor. Custom domain: Cloudflare Dashboard
   → R2 → bucket → Settings → Public Access → Custom Domains (+ DNS'te CNAME,
   **turuncu bulut CDN proxy AÇIK** olmalı). Gerçek IG hesabı bağlı olmalı
   (token geçerli), `QUEUE_CONNECTION=database`,
   supervisor/worker çalışıyor olmalı (`php artisan queue:work`).
2. Custom domain kurulunca: `curl -I <video_url>` → **`206 Partial Content`** ve
   `content-type video/mp4` döndürmeli (restricted 200 ve Range desteği).
   Network sekmesinde de `206` görülmeli. `.r2.dev`'de 200/slow/connection-reset
   görürsen bu throttle'ın işaretidir — custom domain şarttır.

Adımlar:
1. App paneli → Contents → Yeni:
   - Tür = VIDEO, Yüzey = REELS
   - Curator'dan mp4 seç, caption + first_comment doldur
   - Kaydet → content_id not al
2. PublicationsRelationManager'dan hedef hesaba dağıt (scheduled_at = +5 dk)
3. `scheduled_at` gelince worker otomatik yayınlar. İzleme:
   - `publications:health`
   - `storage/logs/publish-flow.log` (flow_id bazlı tüm adımlar)

Beklenen Publication durum makinesi:
```
scheduled → publishing → published      (mutlu yol)
scheduled → publishing → (retry ×2)     → published        (geçici hata)
scheduled → publishing → failed         (kota/token/kalıcı hata;
                                         Telegram "Yayın Başarısız")
scheduled → flagged                     (bağlantı/stok kontrolü uyarısı)
publishing (>1 saat)     → failed       (recover-stuck devreye girer;
                                         media_id varsa asla FAILED'e çekilmez)
```

Bulunan ve düzeltilen iki GERÇEK bug (commit 95155fa):
1. **Video yayın timeout uyumsuzluğu:** Job timeout 85 sn, video container
   poll bütçesi ≈300 sn → her video yayını worker tarafından kesilip 1 saat
   sonra recover-stuck tarafından haksızca FAILED'e çekiliyordu.
   → `timeout=420`, `uniqueFor=1800` (TryPost'un "poll headroom" gerekçesi
   video ayakları için doğruymuş).
2. **Queue çift-koşu riski:** Aktif `database` queue `retry_after=90` <
   yeni timeout 420 → uzun video job'u ikinci worker'da yeniden başlayıp
   ÇİFT YAYIN yapabilirdi. → DB/Redis `retry_after` default 900.

Ders: "TryPost'un agresif ayarlarını alma" kararı tek platform için doğruydu
ama video poll bütçesiyle birlikte değerlendirilmeliydi.

## Gerçek veriyle teşhis + video önizleme fix'i (yolo turu 6)

### Token 190 teşhisi (kod hatası DEĞİL)
Gerçek publishte görülen `code:190 Cannot parse access token` tek bir kod
sorunu değil **veri** sorunu: yayınların çoğu önceki smoke testlerinden kalma
SAHTE InstagramAccount'lara bağlıymış:
- hesap 4 → ig_user_id `195746`, token `"tok"` (3 char!) → /content_publishing_limit
  bu yüzden "Cannot parse token" döndürüyordu.
- hesap 5/6/8 → token `"account-token"` (sahte).
- **GERÇEK hesap yalnızca id 3** (`IGAAWfc0...`, 160 char, expires 2026-10-20):
  pub'lar 5-8 gerçek token'la BAŞARIYLA yayınlandı.
Sonuç: Kod doğru; gerçek Reel yayını için dosyalama **hesap 3'e** yapılmalı,
sahte hesaplara değil. (Sahte hesaplar local dev smoke testi kalıntısı.)

### Video önizleme fix'i (GERÇEK kod bug'ı, commit bu tur)
`App\Models\Media::mediumUrl()/largeUrl()` video için Curator'un çalışan public
URL'sini ezip `route('media.video')` (lokal proxy → R2 stream) döndürüyordu;
HTML5 `<video>` Range/seek isteğini karşılayamadığı için "video açılmıyor".
- Fix: `videoPlayableUrl()` — public disklerde medyanın public `url`'ini
  döndür (R2/r2.dev), non-public diskte proxy rotasına düş.
- Doğrulama: content 13 Reel payload `video_url` =
  `https://pub-... .r2.dev/media/reel-test.mp4` (public, Instagram erişebilir) ✓
- Testler: TenantMediaTest video davranışı public/private dallarına bölündü.

### Kök neden: r2.dev throttle (altyapı, kod değil) — tur 7
Fix, video önizlemesini public `.r2.dev` URL'sine taşıyınca gerçek kök neden
ortaya çıktı: `R2_URL=https://pub-... .r2.dev` Cloudflare'in **yönetilen test**
alan adı — variabil rate-limit + bant genişliği throttle uygular. Semptomlar:
- `Range: bytes=0-` isteği 22s asılı → `net::ERR_CONNECTION_RESET`, 0 byte.
- Küçük statikler (thumbnail) takılmaz, büyük/streaming dosya (5.87 MB mp4)
  throttle'a çarpar → video açılmıyor.
- **Kritik:** Aynı `R2_URL` tabanı Instagram'a giden `video_url/image_url` için
  de kullanılıyor (config `'url' => env('R2_URL')` → tüm URL üretimi tek
  kaynak). Meta `waitForContainerToFinish()`'te bu URL'den indirirken throttle
  olursa container IN_PROGRESS'ta kalabilir → gerçek yayın riski.
Çözüm: bucket'a custom domain bağla (DNS + **CDN proxy turuncu bulut AÇIK**),
sonra `.env`'te `R2_URL=https://media.postimer.com`. KOD DEĞİŞİKLİĞİ GEREKMEZ —
tüm zincir `R2_URL` env'den türer; hardcode yok. `Media::findByPublicUrl()`
host'tan bağımsız path eşleştiği için eski kayıtlar da açılmaya devam eder.
Doğrulama: `curl -I <video_url>` → **206 Partial Content** + Range/seek desteti.

## Kural Notları
- TryPost AGPL-3.0: yalnızca desen esinlenmesi, kod kopyalamak yok.
- Her değişiklik: `vendor/bin/pint --dirty` + ayrı commit.
- **GEÇİCİ KARAR (kullanıcı talimatı):** Bu aşamada TEST YAZILMAYACAK
  (yolo mod). Mevcut testler korunur/yeşil tutulur; yeni özellikler için
  test yazımı sonraki dengeleme fazına ertelendi.
