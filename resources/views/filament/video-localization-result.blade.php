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
                Kaynak dil: {{ strtoupper($localization->source_language) }} → Hedef: {{ strtoupper($localization->target_language) }}
            </span>
        @endif
    </div>

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
            <audio controls preload="none" src="{{ $localization->audioMedia->url }}" class="w-full mb-3"></audio>

            @if ($localization->content->media_url)
                <div
                    x-data="dubCombiner(
                        @js($localization->content->media_url),
                        @js($localization->audioMedia->url),
                        @js('dublaj-'.$localization->content_id),
                        @js($segments),
                        false
                    )"
                    x-init="$watch('burnSubtitles', () => {})"
                    class="space-y-2"
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
                    <p class="text-xs text-gray-500" x-text="status"></p>
                </div>
            @else
                <p class="text-xs text-gray-500">Orijinal video URL'si bulunamadı; dublaj birleştirme atlandı.</p>
            @endif
        </div>
    @endif
</div>
