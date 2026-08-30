<?php

namespace App\Filament\App\Resources\Contents\Pages;

use App\Domain\Publishing\Services\BulkMediaImportService;
use App\Filament\App\Resources\Contents\ContentResource;
use App\Models\Content;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Http\UploadedFile;

class ListContents extends ListRecords
{
    protected static string $resource = ContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->bulkUploadAction(),

            CreateAction::make()->label('İçerik ekle'),
        ];
    }

    /**
     * "Toplu Video Yükle": Birden fazla video dosyasını tek seferde yükler,
     * her biri için ayrı bir Content (VIDEO/REELS) oluşturur. Caption ve
     * ürün isteğe bağlı olarak tüm videolara uygulanır.
     */
    protected function bulkUploadAction(): Action
    {
        return Action::make('bulkUpload')
            ->label('Toplu Video Yükle')
            ->icon('heroicon-m-arrow-up-tray')
            ->color('success')
            ->schema([
                FileUpload::make('files')
                    ->label('Video dosyaları')
                    ->multiple()
                    ->acceptedFileTypes(['video/mp4', 'video/quicktime', 'video/webm'])
                    ->maxSize(102400)
                    ->required()
                    ->disk('local')
                    ->directory('bulk-uploads')
                    ->helperText('Her dosya için ayrı bir içerik oluşturulur. MP4, MOV, WEBM desteklenir.'),

                Select::make('surface')
                    ->label('Yayın Yüzeyi')
                    ->options(Content::surfaces())
                    ->default(Content::SURFACE_REELS)
                    ->required(),

                Textarea::make('caption')
                    ->label('Açıklama (tüm videolara uygulanır)')
                    ->maxLength(2200)
                    ->placeholder('Boş bırakılabilir, sonra düzenlenir.'),

                Textarea::make('first_comment')
                    ->label('Otomatik İlk Yorum (tüm videolara uygulanır)')
                    ->maxLength(2200)
                    ->placeholder('Affiliate link için ideal. Boş bırakılabilir.'),
            ])
            ->modalHeading('Toplu Video Yükle')
            ->modalDescription('Seçtiğiniz tüm video dosyaları otomatik olarak REELS içeriği olarak yüklenir. Sonradan düzenleyip hesaplara dağıtabilirsiniz.')
            ->action(function (array $data, BulkMediaImportService $service): void {
                $files = collect($data['files'] ?? [])
                    ->map(fn (string $path): UploadedFile => new UploadedFile(
                        storage_path('app/private/bulk-uploads/'.$path),
                        basename($path),
                    ))
                    ->all();

                $result = $service->importVideos($files, [
                    'surface' => $data['surface'] ?? Content::SURFACE_REELS,
                    'caption' => filled($data['caption'] ?? null) ? $data['caption'] : null,
                    'first_comment' => filled($data['first_comment'] ?? null) ? $data['first_comment'] : null,
                ]);

                // Geçici dosyaları temizle
                foreach ($data['files'] ?? [] as $tempPath) {
                    @unlink(storage_path('app/private/bulk-uploads/'.$tempPath));
                }

                Notification::make()
                    ->title("{$result['created']} video yüklendi"
                        .($result['failed'] > 0 ? ", {$result['failed']} dosya başarısız" : ''))
                    ->{$result['created'] > 0 ? 'success' : 'danger'}()
                    ->send();
            });
    }
}
