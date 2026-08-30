<?php

namespace App\Filament\App\Resources\Contents\Tables;

use App\Domain\Video\Enums\LocalizationLanguage;
use App\Domain\Video\Enums\LocalizationStatus;
use App\Jobs\GenerateVideoVoiceJob;
use App\Jobs\LocalizeVideoJob;
use App\Models\Content;
use App\Models\InstagramAccount;
use App\Models\Publication;
use App\Models\VideoLocalization;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ContentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('surface')
                    ->label('Tür')
                    ->formatStateUsing(fn (Content $record): string => Content::surfaces()[$record->surface]
                        ?? Content::types()[$record->type]
                        ?? $record->type)
                    ->badge()
                    ->color(fn (string $record): string => match ($record) {
                        'REELS' => 'success',
                        'STORY' => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('caption')
                    ->label('Açıklama')
                    ->limit(50)
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('product.title')
                    ->label('Ürün')
                    ->limit(30)
                    ->placeholder('—')
                    ->toggleable(),

                // Basit publication durum özeti (örn. "Yayınlandı: 2 · Zamanlandı: 1")
                TextColumn::make('publications_summary')
                    ->label('Yayınlar')
                    ->state(fn (Content $record): string => $record->publicationsSummary())
                    ->badge()
                    ->color('gray'),

                // AI Çeviri pipeline durumu (optimistic: job koşarken badge
                // "Analiz ediliyor"/"Seslendiriliyor" gösterir, poll ile güncellenir).
                TextColumn::make('localization_status')
                    ->label('AI Çeviri')
                    ->state(function (Content $record): ?LocalizationStatus {
                        /** @var VideoLocalization|null $localization */
                        $localization = VideoLocalization::latestFor($record);

                        return $localization?->status;
                    })
                    ->formatStateUsing(fn ($state): string => $state instanceof LocalizationStatus
                        ? (string) $state->getLabel()
                        : ($state ?? '—'))
                    ->badge()
                    ->color(fn ($state): string => VideoLocalization::statusColor($state ?? 'pending'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Oluşturulma')
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Medya Türü')
                    ->options(Content::types()),

                SelectFilter::make('surface')
                    ->label('Yayın Yüzeyi')
                    ->options(Content::surfaces()),
            ])
            // Optimistic UI: kuyruktaki AI job'ları ilerledikçe "AI Çeviri"
            // badge'i otomatik güncellenir (polling).
            ->poll('10s')
            ->recordActions([
                EditAction::make(),
                self::localizeAction(),
                self::viewLocalizationAction(),
                self::generateVoiceAction(),
                DeleteAction::make()->label('Sil'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    self::bulkDistributeAction(),
                ]),
            ]);
    }

    /**
     * "AI Çeviri": Videoyu Gemini 2.5 Flash'a gönderir; konuşmayı
     * timestamp'li olarak hedef dile çevirir + ekrandaki yazıları çevirir.
     * LocalizeVideoJob kuyruğuna dispatch edilir.
     *
     * Pilot kuralı: Sonuç otomatik yayına GITMEZ — kullanıcı çeviriyi
     * inceleyip ayrıca seslendirmeyi tetikler.
     */
    private static function localizeAction(): Action
    {
        return Action::make('localizeVideo')
            ->label('AI Çeviri')
            ->icon('heroicon-m-language')
            ->color('warning')
            ->visible(fn (Content $record): bool => $record->isVideo())
            ->requiresConfirmation()
            ->schema([
                Select::make('target_language')
                    ->label('Hedef Dil')
                    ->options(VideoLocalization::languages())
                    ->default('tr')
                    ->required(),
            ])
            ->modalHeading('AI Video Çevirisi Başlat')
            ->modalDescription('Video, Gemini tarafından analiz edilir: konuşma timestamp\'leriyle hedef dile çevrilir ve ekrandaki yazılar tercüme edilir. İşlem birkaç dakika sürebilir; tamamlandığında Telegram\'dan bildirim alırsınız.')
            ->action(function (Content $record, array $data): void {
                $language = LocalizationLanguage::from((string) ($data['target_language'] ?? 'tr'));

                $localization = VideoLocalization::latestForOrCreate($record, $language)
                    ->startNewRun($language);

                dispatch(new LocalizeVideoJob($localization));

                Notification::make()
                    ->success()
                    ->title('Çeviri kuyruğa alındı')
                    ->body('Gemini analizi tamamlandığında video burada güncellenecek — otomatik yayına gitmeyecek.')
                    ->send();
            });
    }

    /**
     * "Yerelleştirme Sonucu": En son yerelleştirme kaydını modalda
     * gösterir — timestamp'li segmentler, ekrandaki yazılar, anlatım
     * metni ve üretilmişse Türkçe ses önizlemesi.
     */
    private static function viewLocalizationAction(): Action
    {
        return Action::make('viewLocalization')
            ->label('Yerelleştirme Sonucu')
            ->icon('heroicon-m-clipboard-document-list')
            ->color('gray')
            ->visible(fn (Content $record): bool => VideoLocalization::query()
                ->where('content_id', $record->id)
                ->exists())
            ->modalHeading('Yerelleştirme Sonucu')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Kapat')
            ->modalContent(fn (Content $record) => view('filament.video-localization-result', [
                'localization' => VideoLocalization::query()
                    ->where('content_id', $record->id)
                    ->latest('id')
                    ->first(),
            ]));
    }

    /**
     * "Türkçe Seslendir": Analiz edilmiş script'i ElevenLabs TTS ile
     * seslendirir. Ses sabittir (ELEVENLABS_VOICE_ID); ElevenLabs
     * Dubbing endpoint'i kullanILMAZ — sadece text-to-speech.
     */
    private static function generateVoiceAction(): Action
    {
        return Action::make('generateVoice')
            ->label('Türkçe Seslendir')
            ->icon('heroicon-m-speaker-wave')
            ->color('success')
            ->visible(function (Content $record): bool {
                /** @var VideoLocalization|null $localization */
                $localization = VideoLocalization::query()
                    ->where('content_id', $record->id)
                    ->latest('id')
                    ->first();

                return $localization !== null
                    && $localization->isAnalyzed()
                    && ! $localization->hasAudio();
            })
            ->requiresConfirmation()
            ->modalHeading('Türkçe Seslendirmeyi Başlat')
            ->modalDescription('Çevrilen metin, yapılandırılmış varsayılan ses ile seslendirilir ve MP3 olarak R2\'ye yüklenir. Oluşan ses + script Edits/final montaj için hazırdır.')
            ->action(function (Content $record): void {
                /** @var VideoLocalization|null $localization */
                $localization = VideoLocalization::query()
                    ->where('content_id', $record->id)
                    ->latest('id')
                    ->first();

                if ($localization === null || ! $localization->isAnalyzed()) {
                    Notification::make()
                        ->danger()
                        ->title('Önce AI Çeviri çalıştırılmalı')
                        ->send();

                    return;
                }

                dispatch(new GenerateVideoVoiceJob($localization));

                Notification::make()
                    ->success()
                    ->title('Seslendirme kuyruğa alındı')
                    ->body('Tamamlandığında ses önizlemesi burada görünecek.')
                    ->send();
            });
    }

    /**
     * "Seçilenleri Dağıt": Birden fazla Content'i seçilen Instagram
     * hesaplarına dağıtır. Başlangıç tarihine göre her yayına sırayla
     * aralık eklenir (stagger) — böylece aynı hesaba arka arkaya
     * video atılmaz, yayınlar zaman içinde yayılır.
     *
     * Örnek: 3 content × 2 hesap = 6 yayın
     *   HesapA: content1 @ 10:00, content2 @ 10:04, content3 @ 10:08
     *   HesapB: content1 @ 10:00, content2 @ 10:04, content3 @ 10:08
     *
     * interval_minutes her yayın arasındaki dakika farkı.
     */
    private static function bulkDistributeAction(): BulkAction
    {
        return BulkAction::make('bulkDistribute')
            ->label('Seçilenleri Dağıt')
            ->icon('heroicon-m-share')
            ->color('success')
            ->schema([
                CheckboxList::make('account_ids')
                    ->label('Instagram Hesapları')
                    ->options(fn (): array => self::accountOptions())
                    ->columns(2)
                    ->required(),

                DateTimePicker::make('start_at')
                    ->label('Başlangıç zamanı')
                    ->helperText('İlk yayın bu zamanda başlar, sonraki videolar belirtilen aralıkla sırayla yayımlanır.')
                    ->minDate(now()->startOfMinute())
                    ->seconds(false)
                    ->required(),

                TextInput::make('interval_minutes')
                    ->label('Yayın aralığı (dakika)')
                    ->numeric()
                    ->default(240)
                    ->minValue(10)
                    ->helperText('Her yayın arasındaki dakika farkı. Örn: 240 = 4 saat ara.')
                    ->required(),
            ])
            ->modalHeading('Seçilen İçerikleri Hesaplara Dağıt')
            ->modalDescription('Seçilen tüm içerikler, seçilen her hesap için sırayla zamanlanır. Her yayına belirtilen dakika aralık eklenir.')
            ->action(function (Collection $records, array $data): void {
                $team = Filament::getTenant();

                $accounts = InstagramAccount::query()
                    ->where('team_id', $team->id)
                    ->whereIn('ig_user_id', (array) ($data['account_ids'] ?? []))
                    ->get();

                $startAt = Carbon::parse($data['start_at']);
                $intervalMinutes = (int) ($data['interval_minutes'] ?? 240);

                $created = 0;
                $skipped = 0;
                $slotIndex = 0;

                // İçerikleri oluşturma sırasına göre diz
                $contents = $records->sortBy('id')->values();

                foreach ($contents as $content) {
                    foreach ($accounts as $account) {
                        $exists = Publication::query()
                            ->where('content_id', $content->id)
                            ->where('instagram_account_id', $account->id)
                            ->exists();

                        if ($exists) {
                            $skipped++;

                            continue;
                        }

                        $scheduledAt = $startAt->copy()->addMinutes($slotIndex * $intervalMinutes);

                        Publication::query()->create([
                            'team_id' => $content->team_id,
                            'content_id' => $content->id,
                            'instagram_account_id' => $account->id,
                            'ig_user_id' => $account->ig_user_id,
                            'status' => Publication::STATUS_SCHEDULED,
                            'scheduled_at' => $scheduledAt,
                            'created_by' => auth()->id(),
                        ]);

                        $created++;
                        $slotIndex++;
                    }
                }

                Notification::make()
                    ->title("{$created} yayın oluşturuldu"
                        .($skipped > 0 ? ", {$skipped} zaten mevcuttu" : ''))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<string, string>
     */
    private static function accountOptions(): array
    {
        return Filament::getTenant()
            ?->instagramAccounts()
            ->orderBy('username')
            ->get()
            ->mapWithKeys(fn ($account): array => [$account->ig_user_id => '@'.($account->username ?? 'Bilinmeyen hesap')])
            ->all() ?? [];
    }
}
