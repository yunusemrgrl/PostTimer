---
paths:
  - 'app/Domain/**/Actions/**'
  - 'app/Domain/**/Data/**'
---

# Domain Actions & DTOs (Beyond CRUD / Spatie pattern)

Use-case başına tek Action sınıfı: `app/Domain/<Domain>/Actions/<VerbObject>.php`,
`__invoke()` ile çağrılır. Logic komut/controller içinde DEĞİL, action'da.
Command/Controller sadece I/O (çağırır + bilgi yazdırır).

Immutable value object / DTO: `app/Domain/<Domain>/Data/<Name>.php`,
`readonly` property promotion, `toArray()` persist için. State taşımak için
associative array YERİNE DTO kullan.

Referans: RecoverStuckLocalizations (action) + RecoverStuckLocalizationsCommand
(command) + CostEstimate (DTO) + EstimateLocalizationCost (action).
