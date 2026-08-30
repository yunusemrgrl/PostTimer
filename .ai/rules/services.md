---
paths:
  - 'app/Domain/**/Services/**'
  - 'app/Support/Http/**'
  - 'app/Services/**'
---

# Services

## Domain service layout (2026-08 reorg)
app/Services klasoru KALDIRILDI. Servisler domain icinde yasar: app/Domain/{Instagram,Video,Publishing,Notification,Stock}/Services/** — provider kontratlari app/Domain/<Domain>/Services/Contracts/** (Strategy). Domain-agnostik HTTP altyapisi app/Support/Http/** icindedir (AbstractExternalApiClient = Template Method tabani: config/timeout/retry/apiError; SafeHttpFetcher). Yeni duz app/Services sinifi OLUSTURMA. Servis tasirken/olustururken ayni-namespace'ten ayrilan siniflar icin artik acik `use` import'u gerektigini unutma.

## Instagram clients are strictly account-scoped (no fallbacks)
No global/fallback Instagram token: config has no INSTAGRAM_ACCESS_TOKEN. The API client is ALWAYS built via InstagramPublishingService::forAccount($account) from the account own (encrypted) token + api_host. forTeam() and the global-token singleton were removed on purpose. Missing token/account must throw a clear RuntimeException - never fall back to another account or env token.
