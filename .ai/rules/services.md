---
paths:
  - 'app/Services/**'
---

# Services

## Instagram clients are strictly account-scoped (no fallbacks)
No global/fallback Instagram token: config has no INSTAGRAM_ACCESS_TOKEN. The API client is ALWAYS built via InstagramPublishingService::forAccount($account) from the account's own (encrypted) token + api_host. forTeam() and the global-token singleton were removed on purpose. Missing token/account must throw a clear RuntimeException — never fall back to another account or env token.
