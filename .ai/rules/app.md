---
paths:
  - 'app/**'
---

# App

## PostTimer 4-domain mimarisi
PostTimer mimarisi 4 bağımsız domenden oluşur: (1) Link Vault — affiliate link/product deposu + URL parser, (2) Post Publishing — medya yükleme + dinamik form (story_link for STORIES, first_comment for REELS/POST/CAROUSEL) + Link Vault eşleştirme (post_id↔link_id), (3) Stock & Price Checker — sadece yayına 15-20 dk kalan postların linklerini tarayan JIT worker + stoksuz/fiyat anomalisinde postu "flagged" moduna çeker, (4) Notification — Telegram bot + event-driven uyarılar + Meta Graph API publisher worker. Geliştirme sırası: 1→2→3→4 (bağımlılık zinciri). Her domain kendi servisi/modeli/testleriyle bağımsız.

## AI localization maliyet konvansiyonu
ElevenLabs ve Gemini ücretli, karakter/çağrı başına faturalandırılır — maliyet bilinçli davran. Varsayılan ElevenLabs TTS modeli `eleven_flash_v2_5`'tir (multilingual_v2'ye göre ~5x ucuz, reels dublaji için yeterli); output format düşük bitrate `mp3_22050_64`'tür. İdempotency zaten duplicate TTS/Gemini çağrısını engeller (retry'da çift ücret yok). Pahalı `multilingual_v2`/yüksek bitrate SADECE kullanıcı açıkça yüksek kalite isterse env ile etkinleştirilir.

