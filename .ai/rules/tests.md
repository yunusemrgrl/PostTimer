---
paths:
  - 'tests/**'
---

# Tests

## Always add a catch-all Http::fake pattern in tests
When using Http::fake(['pattern' => ...]), requests that match NO pattern fall through to the REAL network (PendingRequest::buildStubHandler passes unmatched requests to the live handler). Always add a '*' => Http::response() catch-all (or Http::preventStrayRequests()) so tests never hit external APIs.

## Tenant-scoped Filament tests: create data before panel boot
Create factory data BEFORE calling Filament::setCurrentPanel/setTenant/bootCurrentPanel. Filament registers a `creating` listener per tenant-scoped model that re-associates records with the current tenant, so records created after boot silently get their team_id overwritten. Also use Livewire::test() directly (pest-livewire plugin not installed).
