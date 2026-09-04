@php
    /** @var \App\Models\VideoLocalization $localization */
    $statusKey = $localization->status instanceof \App\Domain\Video\Enums\LocalizationStatus
        ? $localization->status->value
        : (string) $localization->status;
    $statusLabels = \App\Models\VideoLocalization::statuses();
    $statusColor = \App\Models\VideoLocalization::statusColor($localization->status);
    $colors = [
        'success' => 'text-success-600 bg-success-50',
        'warning' => 'text-warning-600 bg-warning-50',
        'danger' => 'text-danger-600 bg-danger-50',
        'gray' => 'text-gray-500 bg-gray-50',
    ];
    $segments = $localization->translation['segments'] ?? [];
    $onScreenText = $localization->translation['on_screen_text'] ?? [];
    $overlays = $localization->translation['overlays'] ?? [];
    $fmt = fn ($seconds): string => sprintf('%02d:%02d', (int) floor(((float) $seconds) / 60), (int) ((float) $seconds) % 60);

    // Akış adımları: pending/analyzing → analyzed → voicing → completed.
    // skipped: Gemini videoyu hedef dilde buldu (gömülü altyazı vb.) — akış yok.
    $statusValue = $localization->status->value;
    $skipped = $statusValue === 'skipped';
    $failed = $statusValue === 'failed';
    $stepOf = ['pending' => 0, 'analyzing' => 0, 'analyzed' => 1, 'voicing' => 2, 'completed' => 3, 'failed' => 3];
    $currentStep = $stepOf[$statusValue] ?? 0;
    $steps = [
        ['label' => 'Gemini Analizi', 'desc' => 'Konuşma + ekran yazıları'],
        ['label' => 'Çeviri Hazır', 'desc' => 'Segmentleri incele'],
        ['label' => 'Seslendirme', 'desc' => 'ElevenLabs TTS'],
        ['label' => 'Dublaj Hazır', 'desc' => 'Dublajlı videoyu indir'],
    ];
    $inProgress = $localization->status->isInProgress();
@endphp

<div class="space-y-4" @if ($inProgress) wire:poll.4s @endif>
    @if (! $skipped)
        {{-- Akış stepper'ı: hangi aşamada olduğumuz tek bakışta okunur --}}
        <ol class="flex items-center gap-1">
        @foreach ($steps as $index => $step)
            @php
                $done = ! $failed && $index < $currentStep;
                $active = ! $failed && $index === $currentStep;
                $isFailedStep = $failed && $index === $currentStep;
            @endphp

            <li class="flex flex-1 flex-col gap-1.5">
                <div class="flex items-center gap-1">
                    <span @class([
                        'flex size-6 shrink-0 items-center justify-center rounded-full border-2 text-[11px] font-bold',
                        'border-success-600 bg-success-600 text-white' => $done,
                        'animate-pulse border-warning-500 bg-warning-100 text-warning-700' => $active,
                        'border-danger-500 bg-danger-100 text-danger-700' => $isFailedStep,
                        'border-gray-300 text-gray-400' => ! $done && ! $active && ! $isFailedStep,
                    ])>
                        @if ($done) ✓ @elseif ($isFailedStep) ✕ @else {{ $index + 1 }} @endif
                    </span>
                    @if (! $loop->last)
                        <span @class(['h-0.5 flex-1 rounded', 'bg-success-500' => $done, 'bg-gray-200' => ! $done])></span>
                    @endif
                </div>
                <div>
                    <p @class(['text-xs font-bold', 'text-gray-900' => $done || $active, 'text-danger-600' => $isFailedStep, 'text-gray-400' => ! $done && ! $active && ! $isFailedStep])>{{ $step['label'] }}</p>
                    <p class="text-[10px] text-gray-400">{{ $step['desc'] }}</p>
                </div>
            </li>
        @endforeach
    </ol>
    @endif

    @php
        // Duruma göre "şimdi ne yapacağım?" yönlendirmesi: kullanıcı
        // akışın neresinde olduğunu ve bir sonraki eylemi tek bakışta görür.
        $nextStep = match ($statusValue) {
            'skipped' => [
                'tone' => 'info',
                'icon' => 'heroicon-o-check-badge',
                'title' => 'Bu video zaten hedef dilde',
                'body' => trim(($localization->detectionReason()
                    ? 'Gemini: '.$localization->detectionReason().' '
                    : '')
                    .'Çeviri ve seslendirme yapılmadı — videoyu olduğu gibi yayına planlayabilirsin.'),
            ],
            'pending', 'analyzing' => [
                'tone' => 'warning',
                'icon' => 'heroicon-o-clock',
                'title' => 'Analiz sürüyor…',
                'body' => 'Gemini videoyu inceliyor. Bitince Telegram’dan bildirim alırsın; bu panel açıkken otomatik güncellenir. Beklemen gerekmiyor, paneli kapatıp başka işine dönebilirsin.',
            ],
            'analyzed' => [
                'tone' => 'info',
                'icon' => 'heroicon-o-arrow-right-circle',
                'title' => 'Çeviri hazır — sıra seslendirmede',
                'body' => 'Aşağıdaki segmentleri incele. Her şey doğruysa bu paneli kapat ve kartın "İşlemler" (⋯) menüsünden "Seslendirmeyi Başlat"ı çalıştır.',
            ],
            'voicing' => [
                'tone' => 'warning',
                'icon' => 'heroicon-o-speaker-wave',
                'title' => 'Seslendirme üretiliyor…',
                'body' => 'ElevenLabs sesi üretiyor. Tamamlanınca bu panelde oynatıcı ve dublaj indirme butonu belirecek.',
            ],
            'completed' => [
                'tone' => 'success',
                'icon' => 'heroicon-o-check-circle',
                'title' => 'Dublaj hazır!',
                'body' => 'Aşağıdan dublajlı videoyu indir. Yayına almak için paneli kapatıp karta tıkla (Düzenle) ve yayınını planla.',
            ],
            default => [
                'tone' => 'danger',
                'icon' => 'heroicon-o-exclamation-triangle',
                'title' => 'Bir sorun oluştu',
                'body' => 'Paneli kapatıp kartın "İşlemler" (⋯) menüsünden "AI Dublaj" ile işlemi yeniden başlatabilirsin.',
            ],
        };
        $toneClasses = [
            'warning' => 'border-warning-300 bg-warning-50 text-warning-800',
            'info' => 'border-info-300 bg-info-50 text-info-800',
            'success' => 'border-success-300 bg-success-50 text-success-800',
            'danger' => 'border-danger-300 bg-danger-50 text-danger-800',
        ][$nextStep['tone']];
    @endphp

    {{-- Sonraki adım rehberi: kullanıcıyı akışta tutan tek eylem çağrısı --}}
    <div class="flex items-start gap-3 rounded-xl border-2 p-3 {{ $toneClasses }}">
        <x-filament::icon :icon="$nextStep['icon']" class="mt-0.5 size-5 shrink-0" />
        <div>
            <p class="text-sm font-bold">{{ $nextStep['title'] }}</p>
            <p class="mt-0.5 text-xs leading-relaxed opacity-90">{{ $nextStep['body'] }}</p>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <span class="fi-badge rounded-md px-2 py-1 text-xs font-medium {{ $colors[$statusColor] }}">
            {{ $statusLabels[$statusKey] ?? $statusKey }}
        </span>

        @if ($localization->source_language)
            <span class="text-xs text-gray-500">
                Kaynak dil: {{ strtoupper($localization->source_language) }} → Hedef: {{ strtoupper($localization->target_language->value ?? '') }}
            </span>
        @endif
    </div>

    @if ($skipped)
        {{-- Akıllı atlama kanıtı: Gemini'nin tespiti şeffaf gösterilir --}}
        <div class="flex flex-wrap items-center gap-1.5">
            @if ($localization->translation['has_burned_in_subtitles'] ?? false)
                <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600">
                    <x-filament::icon icon="heroicon-o-film" class="size-3" />
                    Gömülü altyazı: {{ strtoupper((string) ($localization->translation['burned_in_subtitle_language'] ?? $localization->target_language->value)) }}
                </span>
            @endif
            <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600">
                <x-filament::icon icon="heroicon-o-megaphone" class="size-3" />
                Konuşma dili: {{ strtoupper((string) $localization->source_language) }}
            </span>
        </div>
    @endif

    @if ((float) $localization->estimated_cost_usd > 0)
        <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
            <span class="font-semibold text-gray-700">Tahmini maliyet:</span>
            <span class="font-mono">${{ number_format((float) $localization->estimated_cost_usd, 4) }}</span>
            @if (filled($localization->cost_breakdown))
                <span class="text-gray-400">
                    (Gemini ${{ number_format((float) ($localization->cost_breakdown['gemini'] ?? 0), 4) }}
                    @if (isset($localization->cost_breakdown['tts']) && (float) $localization->cost_breakdown['tts'] > 0)
                        + TTS ${{ number_format((float) $localization->cost_breakdown['tts'], 4) }}
                    @endif
                    )
                </span>
            @endif
        </div>
    @endif

    @if ($localization->error_message)
        <div class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">
            {{ $localization->error_message }}
        </div>
    @endif

    @if (filled($segments))
        <details class="group rounded-xl border border-gray-200">
            <summary class="flex cursor-pointer items-center justify-between p-3 text-sm font-semibold text-gray-700">
                Konuşma Segmentleri
                <span class="flex items-center gap-2 text-xs font-normal text-gray-400">
                    {{ count($segments) }} segment
                    <x-filament::icon icon="heroicon-m-chevron-down" class="size-4 transition-transform group-open:rotate-180" />
                </span>
            </summary>
            <div class="max-h-72 space-y-2 overflow-y-auto border-t border-gray-100 p-3">
                @foreach ($segments as $segment)
                    <div class="rounded-lg border border-gray-200 p-3 text-sm">
                        <span class="font-mono text-xs text-gray-400">
                            {{ $fmt($segment['start']) }} – {{ $fmt($segment['end']) }}
                        </span>

                        @if (($segment['source'] ?? '') !== '')
                            <p class="mt-1 text-xs italic text-gray-500">{{ $segment['source'] }}</p>
                        @endif

                        <p class="mt-0.5 font-medium text-gray-800">{{ $segment['translation'] }}</p>
                    </div>
                @endforeach
            </div>
        </details>
    @elseif ($skipped)
        <p class="text-sm text-gray-500">Bu videoya çeviri gerekmedi — konuşma ve gömülü metinler yukarıdaki tespitle listelenir.</p>
    @elseif (! $localization->error_message)
        <p class="text-sm text-gray-500">Analiz sürüyor veya henüz başlatılmadı.</p>
    @endif

    @if (filled($onScreenText))
        <details class="group rounded-xl border border-gray-200">
            <summary class="flex cursor-pointer items-center justify-between p-3 text-sm font-semibold text-gray-700">
                Ekrandaki Yazılar
                <x-filament::icon icon="heroicon-m-chevron-down" class="size-4 text-gray-400 transition-transform group-open:rotate-180" />
            </summary>
            <ul class="list-inside list-disc space-y-1 border-t border-gray-100 p-3 text-sm text-gray-700">
                @foreach ($onScreenText as $entry)
                    <li>{{ $entry }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    @if (filled($localization->script))
        <details class="group rounded-xl border border-gray-200">
            <summary class="flex cursor-pointer items-center justify-between p-3 text-sm font-semibold text-gray-700">
                Anlatım Metni
                <x-filament::icon icon="heroicon-m-chevron-down" class="size-4 text-gray-400 transition-transform group-open:rotate-180" />
            </summary>
            <pre class="max-h-60 overflow-y-auto whitespace-pre-wrap border-t border-gray-100 bg-gray-50 p-3 text-xs text-gray-700">{{ $localization->script }}</pre>
        </details>
    @endif

    @if ($localization->hasAudio() && $localization->audioMedia !== null)
        <div>
            <h4 class="mb-2 text-sm font-semibold text-gray-700">Dublaj Sesi Önizleme</h4>

            {{-- Wavesurfer.js waveform (BSD-3-Clause) --}}
            <div
                x-data="audioWaveformer(@js($localization->audioMedia->url))"
                x-init="init()"
                class="space-y-2"
            >
                <div x-ref="waveform" class="rounded-lg bg-gray-50 p-2"></div>
                <button
                    type="button"
                    x-on:click="toggle"
                    :disabled="!ready"
                    x-text="playing ? 'Durdur' : 'Oynat'"
                    class="rounded-md bg-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700 disabled:opacity-50"
                ></button>
            </div>
        </div>
    @endif

    @if ($localization->content->media_url)
                {{-- Önce/Sonra video karşılaştırma. showDubbed dış scope'ta,
                     dublaj önizlemesi iç dubCombiner scope'unda üretilir
                     (iç scope dış değişkeni okur). --}}
                <div
                    x-data="dubCombiner(
                        @js($localization->content->media_url),
                        @js($localization->hasAudio() && $localization->audioMedia !== null ? $localization->audioMedia->url : null),
                        @js('dublaj-'.$localization->content_id),
                        @js($segments),
                        false,
                        @js($overlays)
                    )"
                    class="mt-3 space-y-2"
                >
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            x-on:click="showDubbed = false"
                            :class="showDubbed ? 'bg-gray-200 text-gray-600' : 'bg-primary-600 text-white'"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold"
                        >Orijinal Video</button>
                        <button
                            type="button"
                            x-on:click="showDubbed = true"
                            :class="showDubbed ? 'bg-primary-600 text-white' : 'bg-gray-200 text-gray-600'"
                            class="rounded-md px-3 py-1.5 text-xs font-semibold"
                        >Dublajlı Sonuç</button>
                    </div>
                    <video
                        x-show="!showDubbed"
                        controls
                        preload="none"
                        src="{{ $localization->content->media_url }}"
                        class="w-full rounded-lg"
                    ></video>

                    {{-- Dublaj üretme + önizleme + progress bar --}}
                    <div class="space-y-2">
                        <div x-show="showDubbed" class="space-y-2">
                            {{-- Her yeni blob için <video> elementini sıfırdan kur (x-if remount).
                                 Aynı element üzerinde :src değiştirilirse Chromium gizli iken
                                 metadata yüklemeyip süreyi 0.00 gösterebiliyor. --}}
                            <template x-if="previewUrl">
                                <video
                                    :src="previewUrl"
                                    controls
                                    playsinline
                                    preload="auto"
                                    class="w-full rounded-lg border-2 border-gray-900 dark:border-white/40"
                                ></video>
                            </template>
                            <p x-show="! previewUrl" class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">
                                @if ($localization->hasAudio())
                                    "Önizle" ile dublajlı videoyu tarayıcıda üret ve burada izle — beğenmezsen ayarları değiştirip yeniden üretebilirsin.
                                @else
                                    Bu videoda seslendirme yok — <strong>orijinal ses (müzik vb.) korunur</strong>, yalnızca ekrandaki yazıların Türkçe çevirisi videoya yakılır. "Önizle" ile sonucu burada izleyebilirsin.
                                @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" x-model="burnSubtitles" class="rounded border-gray-300 text-primary-600">
                                <span>Altyazı ekle (videoya gömülü)</span>
                            </label>
                            <button
                                type="button"
                                x-on:click="combine('preview')"
                                :disabled="busy"
                                class="rounded-lg border-2 border-gray-900 bg-white px-4 py-2 text-sm font-semibold text-gray-900 shadow-[3px_3px_0_0_rgb(9_22_29)] transition-all hover:translate-x-0.5 hover:translate-y-0.5 hover:shadow-none disabled:opacity-60"
                            >▶️ Önizle</button>
                            <button
                                type="button"
                                x-on:click="combine('download')"
                                :disabled="busy"
                                class="fi-btn fi-btn-size-md fi-color-primary rounded-lg px-4 py-2 text-sm font-semibold shadow-sm bg-primary-600 text-white inline-flex items-center gap-2 disabled:opacity-60"
                            >⬇️ İndir</button>
                        </div>

                        {{-- Progress bar --}}
                        <div x-show="busy || progress > 0" class="space-y-1">
                            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                                <div
                                    class="h-full rounded-full bg-primary-600 transition-all duration-300"
                                    :style="`width: ${progress}%`"
                                ></div>
                            </div>
                            <p class="text-xs text-gray-500">
                                <span x-text="status"></span>
                                <span x-show="progress > 0">(<span x-text="progress"></span>%)</span>
                                {{-- Encode hızı: "yavaş ama akıcı" ile "takıldı" ayrımı için --}}
                                <span x-show="encodeFps" class="tabular-nums">· <span x-text="encodeFps"></span> fps</span>
                            </p>
                        </div>
                    </div>
            @else
                <p class="text-xs text-gray-500">Orijinal video URL'si bulunamadı; dublaj birleştirme atlandı.</p>
            @endif
</div>
