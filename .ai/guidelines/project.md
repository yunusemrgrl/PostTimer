# Proje Kuralları — Multi-tenant Filament Boilerplate

Bu dosya, Laravel Boost'un `boost:install` sonrası AI ajanlarına otomatik
olarak yüklediği rehberlere ek olarak, bu projeye özgü kuralları tanımlar.
`.ai/guidelines/` altındaki dosyalar Boost tarafından otomatik keşfedilir.

## Mimari

- **İki Filament paneli var:**
  - `admin` (`/admin`) — **tenant'sız**, merkezi panel. Sadece
    `super_admin` rolüne sahip kullanıcılar girebilir. Buradan TÜM
    hesaplar (Team), kullanıcılar ve platform rolleri yönetilir.
  - `app` (`/app`) — **tenant'lı** panel. `App\Models\Team` tenant
    modelidir. Her kullanıcı, üyesi olduğu hesaplar arasında geçiş
    yapabilir. `super_admin` kullanıcılar TÜM hesapları görür ve
    aralarında geçiş yapabilir (bkz. `User::getTenants()`).

- **İki ayrı rol/izin katmanı vardır, birbirine karıştırılmamalı:**
  1. **Platform rolleri** (`spatie/laravel-permission`, `roles`/`permissions`
     tabloları) — tenant'a bağlı DEĞİLDİR. Şu an sadece `super_admin` ve
     `support` var. `admin` paneline erişim ve platform çapındaki
     yetkiler buradan gelir.
  2. **Tenant içi roller** (`team_user.role` kolonu: `owner` / `admin` /
     `member`) — her hesaba özeldir, Spatie ile YÖNETİLMEZ. `app`
     panelindeki `MemberResource` ve `TeamMemberPolicy` bunu kullanır.

- `App\Providers\AppServiceProvider::boot()` içindeki `Gate::before`,
  `super_admin` rolüne sahip kullanıcıları TÜM policy kontrollerinden
  otomatik geçirir. Yeni bir Policy eklerken süper admin için ayrıca
  kontrol yazmaya gerek yoktur.

## Yeni bir tenant-scoped kaynak eklerken

- Model, tenant modeline (`Team`) doğrudan `belongsTo` ilişkisiyle
  bağlı olmalı (many-to-many ise `TeamMember` deseninde olduğu gibi
  ayrı bir pivot-model kullanın), aksi halde Filament'in otomatik
  tenant scoping'i çalışmaz.
- Yeni resource'u `app/Filament/App/Resources/...` altına, admin-only
  (tüm hesaplara bakan) kaynakları `app/Filament/Admin/Resources/...`
  altına koyun.

## Filament sürümü

Bu proje **Filament 5** kullanır. Form/infolist bileşenleri birleşik
`Filament\Schemas\Schema` API'si üzerinden tanımlanır (`Filament\Forms\Form`
DEĞİL). Tablo action'ları `Filament\Actions\*` namespace'i altındadır
(`Filament\Tables\Actions\*` DEĞİL). Yeni kod yazarken önce
`php artisan make:filament-resource` ile üretilen iskeleti referans alın.
