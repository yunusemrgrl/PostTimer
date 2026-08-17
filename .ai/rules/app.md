---
paths:
  - 'app/**'
---

# App

## PostTimer 4-domain mimarisi
PostTimer mimarisi 4 bağımsız domenden oluşur: (1) Link Vault — affiliate link/product deposu + URL parser, (2) Post Publishing — medya yükleme + dinamik form (story_link for STORIES, first_comment for REELS/POST/CAROUSEL) + Link Vault eşleştirme (post_id↔link_id), (3) Stock & Price Checker — sadece yayına 15-20 dk kalan postların linklerini tarayan JIT worker + stoksuz/fiyat anomalisinde postu "flagged" moduna çeker, (4) Notification — Telegram bot + event-driven uyarılar + Meta Graph API publisher worker. Geliştirme sırası: 1→2→3→4 (bağımlılık zinciri). Her domain kendi servisi/modeli/testleriyle bağımsız.
