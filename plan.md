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
- MCP server + `schedule_publication` tool'u (dependency onayı gerektirir)
- URL-import medya feature'ı (SafeHttpFetcher/SSRF katmanıyla birlikte)
- Horizon değerlendirmesi (yük kanıtlandığında)

## Kural Notları
- TryPost AGPL-3.0: yalnızca desen esinlenmesi, kod kopyalamak yok.
- Her değişiklik: test + `vendor/bin/pint --dirty` + ayrı commit.
