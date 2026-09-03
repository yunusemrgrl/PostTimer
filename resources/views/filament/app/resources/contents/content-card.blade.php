@php
    /** @var \App\Models\Content $getRecord */
    $record = $getRecord();

    $surfaceLabel = \App\Models\Content::surfaces()[$record->surface] ?? \App\Models\Content::types()[$record->type] ?? $record->type;
    $isVideo = $record->isVideo();

    // Medya URL'lerini normalize et: children hem string liste hem de
    // ['url' => ...] dizi liste olabilir (factory/legacy veri). Bu yüzden
    // her öğeyi düzgün bir string URL'ye çeviririz.
    $normalizeMediaItem = function (mixed $item): ?string {
        if (is_string($item) && $item !== '') {
            return $item;
        }

        if (is_array($item)) {
            $url = $item['url'] ?? ($item['src'] ?? null);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    };

    // Media: tek ise media_url, karusel ise children.
    $mediaItems = collect($record->children ?? [])
        ->map($normalizeMediaItem)
        ->filter()
        ->values()
        ->all();

    if (blank($mediaItems)) {
        $mediaItems = filled($record->media_url) ? [$record->media_url] : [];
    }

    $thumbnail = $record->thumbnail_url ?? null;
    $hasCover = filled($thumbnail) || filled($mediaItems);
@endphp

<div
    {{ $attributes->class(['group relative flex h-full flex-col overflow-hidden rounded-xl border-2 border-gray-950/10 bg-white transition-all hover:border-gray-950 hover:shadow-[4px_4px_0_0_rgb(9_22_29)] dark:border-white/10 dark:bg-gray-900 dark:hover:border-white/60 dark:hover:shadow-[4px_4px_0_0_rgba(255,255,255,0.2)]']) }}
>
    {{-- Kapak (medya) --}}
    <div class="relative aspect-square w-full shrink-0 overflow-hidden bg-gray-100 dark:bg-gray-950/40">
        @if ($thumbnail)
            <img
                src="{{ $thumbnail }}"
                alt=""
                loading="lazy"
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
            />
        @elseif (filled($mediaItems))
            @php $first = (string) $mediaItems[0]; @endphp
            @if ($isVideo)
                <video src="{{ $first }}" class="h-full w-full object-cover" muted preload="metadata"></video>
            @else
                <img src="{{ $first }}" alt="" loading="lazy" class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105" />
            @endif
        @else
            <div class="flex h-full items-center justify-center text-gray-300 dark:text-gray-600">
                <x-filament::icon icon="heroicon-o-photo" class="size-12" />
            </div>
        @endif

        {{-- Üst rozet: yüzey + video --}}
        <div class="absolute left-2 top-2 flex items-center gap-1">
            <span class="inline-flex items-center rounded-full border-2 border-gray-950 bg-white/95 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-900 dark:border-white/60 dark:bg-gray-900 dark:text-white">
                {{ $surfaceLabel }}
            </span>

            @if ($isVideo)
                <span class="flex size-6 items-center justify-center rounded-full border-2 border-gray-950 bg-gray-950/80 text-white">
                    <x-filament::icon icon="heroicon-o-play" class="size-3.5" />
                </span>
            @endif
        </div>

        @if (count($mediaItems) > 1)
            <span class="absolute right-2 top-2 rounded-full bg-gray-950/70 px-1.5 py-0.5 text-[10px] font-bold text-white">
                {{ count($mediaItems) }} parça
            </span>
        @endif
    </div>

    {{-- İçerik: caption + meta satırı + durum rozetleri --}}
    <div class="flex flex-1 flex-col gap-2.5 p-3">
        <div class="flex items-start justify-between gap-2">
            <div
                class="line-clamp-2 flex-1 text-sm font-semibold leading-snug text-gray-900 dark:text-gray-100"
            >
                {{ $record->caption ?: '—' }}
            </div>
            <span class="shrink-0 text-[10px] font-medium text-gray-400">{{ $record->created_at?->format('d.m.Y') }}</span>
        </div>

        @if ($record->product?->title)
            <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-o-link" class="size-3.5" />
                {{ $record->product->title }}
            </span>
        @endif

        <div class="mt-auto flex flex-wrap items-center gap-1.5">
            {{-- Publication durum özeti --}}
            @php $pubSummary = $record->publicationsSummary(); @endphp
            @if ($pubSummary !== '—')
                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {{ $pubSummary }}
                </span>
            @endif

            {{-- AI Çeviri durumu --}}
            @php
                $localization = \App\Models\VideoLocalization::latestFor($record);
                $locStatus = $localization?->status;
                $locClasses = [
                    'success' => 'bg-success-50 text-success-700',
                    'info' => 'bg-info-50 text-info-700',
                    'warning' => 'bg-warning-50 text-warning-700',
                    'danger' => 'bg-danger-50 text-danger-700',
                    'gray' => 'bg-gray-100 text-gray-600',
                ][$locStatus instanceof \App\Domain\Video\Enums\LocalizationStatus ? $locStatus->getColor() : null]
                    ?? 'bg-gray-100 text-gray-600';
            @endphp
            @if ($locStatus instanceof \App\Domain\Video\Enums\LocalizationStatus)
                <span @class([
                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold',
                    $locClasses,
                ])>
                    @if ($locStatus->isInProgress())
                        <span class="size-1.5 animate-pulse rounded-full bg-current"></span>
                    @endif
                    {{ $locStatus->getLabel() }}
                </span>
            @endif

        </div>
    </div>
</div>