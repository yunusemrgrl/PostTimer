---
paths:
  - 'bootstrap/**'
---

# Bootstrap

## Register every Filament panel provider in bootstrap/providers.php
Every PanelProvider class must be listed in bootstrap/providers.php. AppPanelProvider (the default, tenant-scoped /app panel) was missing, which made the whole tenant panel unreachable ("No default Filament panel is set") — new panels must be registered there.
