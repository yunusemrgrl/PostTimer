@props([
    'item' => null,
    'src' => null,
    'controls' => null,
    'lazy' => null,
    'player' => false,
    'iconClasses' => '',
    'constrained' => false,
])

@php
    if ($item && is_array($item)) {
        $item = (object) $item;
    }

    if (!$src) {
        $src = $item->url;
    }

    $isVideo = curator()->isVideo($item->ext);

    /*
     * Curator bazı durumlarda curations'ı JSON string,
     * bazı durumlarda array olarak verebilir.
     */
    $curations = $item->curations ?? [];

    if (is_string($curations)) {
        $curations = json_decode($curations, true) ?? [];
    }

    /*
     * Video thumbnail URL'sini belirle.
     */
    $thumbnailUrl = null;

    if ($isVideo) {
        if (filled($item->thumbnail_url ?? null)) {
            $thumbnailUrl = $item->thumbnail_url;
        } elseif (filled($curations['video_thumbnail'] ?? null)) {
            $thumbnailUrl = route('media.thumbnail', [
                'media' => $item->name,
            ]);
        }
    }
@endphp

@if ($isVideo && $player)
    <div class="relative w-full h-full overflow-hidden">
        <video
            src="{{ route('media.video', ['media' => $item->name]) }}"
            @if ($thumbnailUrl)
                poster="{{ $thumbnailUrl }}"
            @endif
            @if ($controls)
                controls
            @endif
            preload="{{ $lazy ? 'none' : 'metadata' }}"
            playsinline
            class="w-full h-full object-contain"
            {{ $attributes->except([
                'src',
                'controls',
                'lazy',
                'item',
            ]) }}
        >
            Tarayıcınız video oynatmayı desteklemiyor.
        </video>
    </div>

@elseif (curator()->isPreviewable($item->ext))
    <img
        src="{{ $src }}"
        alt="{{ $item->alt ?? '' }}"
        loading="{{ $lazy ? 'lazy' : 'eager' }}"
        {{
            $attributes
                ->merge([
                    'width' => $item->width,
                    'height' => $item->height,
                ])
                ->except([
                    'src',
                    'alt',
                    'lazy',
                    'item',
                ])
                ->class([
                    'object-cover' => ! $constrained,
                    'object-contain' => $constrained,
                ])
        }}
    />

@else
    <div
        @class([
            'curator-document-image grid place-items-center w-full h-full text-xs uppercase relative bg-gray-100 dark:bg-gray-900',
            $attributes->get('class'),
        ])
        {{ $attributes->except([
            'src',
            'alt',
            'lazy',
            'item',
            'class',
        ]) }}
    >
        @if ($isVideo)
            @svg('heroicon-o-film', [
            'class' => 'opacity-20 ' . $iconClasses,
            ])

            <span class="block absolute">
                {{ $item->ext }}
            </span>

        @elseif (curator()->isAudio($item->ext))
            @svg('heroicon-o-speaker-wave', [
            'class' => 'opacity-20 ' . $iconClasses,
            ])

            <span class="block absolute">
                {{ $item->ext }}
            </span>

        @else
            @svg('heroicon-o-document', [
            'class' => 'opacity-20 ' . $iconClasses,
            ])

            <span class="block absolute">
                {{ $item->ext }}
            </span>
        @endif

        <span class="sr-only">
            {{ $item->pretty_name }}
        </span>
    </div>
@endif
