---
paths:
  - 'app/Models/**'
---

# Models

## Override newFactory() for models extending Curator's Media
App\Models\Media extends Awcodes\Curator\Models\Media, which uses the HasPackageFactory trait. That trait's newFactory() resolves the factory as "{namespace before Models}\Database\Factories\{Model}Factory" = "App\Database\Factories\MediaFactory" — but this app's factories live under "Database\Factories\" (composer.json PSR-4). So Media::factory() throws "Class App\Database\Factories\MediaFactory not found" despite a valid MediaFactory existing at database/factories/MediaFactory.php. Always override newFactory() in any app-level model that extends a Curator (or other) package model using HasPackageFactory, returning the app-namespaced factory.
