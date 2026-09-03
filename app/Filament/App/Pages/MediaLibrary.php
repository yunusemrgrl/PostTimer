<?php

namespace App\Filament\App\Pages;

use App\Filament\Curator\TenantPathGenerator;
use App\Models\Media;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use UnitEnum;

/**
 * Karakteristik medya kütüphanesi: Curator'ın tablo görünümü yerine
 * sürükle-bırak yükleme + sekme filtreleri + kart grid'i sunar.
 *
 * Storage katmanı değişmez: dosyalar Curator diske, TenantPathGenerator
 * yoluna yazılır ve MediaObserver (magic-byte doğrulaması, video thumbnail
 * işaretleme) aynen çalışır. CuratorPicker ile seçilen medyalar da burada
 * görünür — tek kaynak `curator` tablosudur.
 */
class MediaLibrary extends Page
{
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Instagram';

    protected static ?string $navigationLabel = 'Medya Kütüphanesi';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.app.pages.media-library';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public string $search = '';

    /** Tümü | Görseller | Videolar */
    public string $tab = 'all';

    public function getTitle(): string|Htmlable
    {
        return 'Medya Kütüphanesi';
    }

    /**
     * Sürükle-bırak / dosya seçimi tamamlandığında her dosyayı Curator
     * diske tenant yoluna yazar ve Media kaydını oluşturur.
     */
    public function updatedUploads(): void
    {
        $this->validate([
            'uploads.*' => ['mimetypes:image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm', 'max:102400'],
        ]);

        $tenant = Filament::getTenant();

        abort_unless($tenant !== null, 403);

        $disk = (string) config('curator.default_disk', 'public');
        $visibility = (string) config('curator.default_visibility', 'public');
        $directory = TenantPathGenerator::pathForTeam($tenant);

        foreach ($this->uploads as $file) {
            $name = (string) Str::uuid();
            $ext = strtolower((string) $file->guessExtension());
            $path = $file->storeAs($directory, "{$name}.{$ext}", ['disk' => $disk]);

            if (! is_string($path)) {
                continue;
            }

            [$width, $height] = $this->imageDimensions($disk, $path);

            Media::query()->create([
                'disk' => $disk,
                'directory' => $directory,
                'visibility' => $visibility,
                'name' => "{$name}.{$ext}",
                'path' => $path,
                'width' => $width,
                'height' => $height,
                'size' => $file->getSize(),
                'type' => $file->getMimeType(),
                'ext' => $ext,
                'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'team_id' => $tenant->getKey(),
            ]);
        }

        $this->reset('uploads');

        Notification::make()
            ->title('Medya yüklendi')
            ->success()
            ->send();
    }

    public function deleteMedia(int $mediaId): void
    {
        $media = $this->mediaQuery()->findOrFail($mediaId);

        Storage::disk((string) $media->disk)->delete((string) $media->path);
        $media->deleteQuietly();

        Notification::make()
            ->title('Medya silindi')
            ->success()
            ->send();
    }

    /**
     * Görsellerin boyutlarını okur; video ve okunamayan dosyalarda null.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function imageDimensions(string $disk, string $path): array
    {
        try {
            $size = @getimagesizefromstring((string) Storage::disk($disk)->get($path));

            return $size !== false ? [(int) $size[0], (int) $size[1]] : [null, null];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /**
     * Tenant'a ait medya sorgusu — panel tenant scoping'i Page'lerde
     * otomatik çalışmadığından açıkça filtrelenir.
     */
    private function mediaQuery(): Builder
    {
        return Media::query()
            ->where('team_id', Filament::getTenant()?->getKey())
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('title', 'like', "%{$this->search}%")
                        ->orWhere('name', 'like', "%{$this->search}%");
                });
            })
            ->when($this->tab === 'images', fn (Builder $query) => $query->where('type', 'like', 'image/%'))
            ->when($this->tab === 'videos', fn (Builder $query) => $query->where('type', 'like', 'video/%'))
            ->latest('id');
    }

    public function getMediaProperty(): Paginator
    {
        return $this->mediaQuery()->simplePaginate(24);
    }
}
