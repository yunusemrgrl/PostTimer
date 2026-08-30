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
    $fmt = fn ($seconds): string => sprintf('%02d:%02d', (int) floor(((float) $seconds) / 60), (int) ((float) $seconds) % 60);
@endphp

<div class="space-y-4">
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
        <div>
            <h4 class="mb-2 text-sm font-semibold text-gray-700">Konuşma Segmentleri</h4>
            <div class="max-h-80 space-y-2 overflow-y-auto pr-1">
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
        </div>
    @elseif (! $localization->error_message)
        <p class="text-sm text-gray-500">Analiz sürüyor veya henüz başlatılmadı.</p>
    @endif

    @if (filled($onScreenText))
        <div>
            <h4 class="mb-2 text-sm font-semibold text-gray-700">Ekrandaki Yazılar</h4>
            <ul class="list-inside list-disc space-y-1 text-sm text-gray-700">
                @foreach ($onScreenText as $entry)
                    <li>{{ $entry }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (filled($localization->script))
        <div>
            <h4 class="mb-2 text-sm font-semibold text-gray-700">Anlatım Metni</h4>
            <pre class="max-h-60 overflow-y-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ $localization->script }}</pre>
        </div>
    @endif

    @if ($localization->hasAudio() && $localization->audioMedia !== null)
        <div>
            <h4 class="mb-2 text-sm font-semibold text-gray-700">Türkçe Ses Önizleme</h4>

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

            @if ($localization->content->media_url)
                {{-- Önce/Sonra video karşılaştırma --}}
                <div x-data="{ showDubbed: false }" class="mt-3 space-y-2">
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
                    <p x-show="showDubbed" class="rounded-lg bg-gray-50 p-4 text-sm text-gray-500">
                        Dublajlı videoyu üretmek için aşağıdaki butona tıklayın. Üretilen video otomatik indirilir.
                    </p>
                </div>

                {{-- Dublaj üretme + progress bar --}}
                <div
                    x-data="dubCombiner(
                        @js($localization->content->media_url),
                        @js($localization->audioMedia->url),
                        @js('dublaj-'.$localization->content_id),
                        @js($segments),
                        false
                    )"
                    class="mt-3 space-y-2"
                >
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" x-model="burnSubtitles" class="rounded border-gray-300 text-primary-600">
                        <span>Altyazı ekle (videoya gömülü)</span>
                    </label>
                    <button
                        type="button"
                        x-on:click="combine"
                        :disabled="busy"
                        x-text="busy ? 'Birleştiriliyor…' : 'Dublajlı Videoyu İndir'"
                        class="fi-btn fi-btn-size-md fi-color-primary rounded-lg px-4 py-2 text-sm font-semibold shadow-sm bg-primary-600 text-white inline-flex items-center gap-2 disabled:opacity-60"
                    >🎬 Dublajlı Videoyu İndir</button>

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
                        </p>
                    </div>
                </div>
            @else
                <p class="text-xs text-gray-500">Orijinal video URL'si bulunamadı; dublaj birleştirme atlandı.</p>
            @endif
        </div>
    @endif
</div>
