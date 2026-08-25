@props([
    'item' => null,
    'src' => null,
    'controls' => null,
    'lazy' => null,
    'player' => false,
    'poster' => null,
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

    $poster ??= $item->thumbnail_url ?? null;
@endphp

@if (curator()->isPreviewable($item->ext))
    <img
        src="{{ $src }}"
        alt="{{ $item->alt ?? '' }}"
        loading="{{ $lazy ? 'lazy' : 'eager' }}"
        {{
            $attributes
                ->except(['src', 'alt', 'lazy', 'item', 'width', 'height'])
                ->class([
                    'h-full w-full object-cover' => ! $constrained,
                    'h-full w-full object-contain' => $constrained,
                ])
        }}
    />
@elseif (curator()->isVideo($item->ext) && $player)
    <video
        src="{{ $src }}"
        @if ($controls)
            controls
        @endif
        @if (filled($poster))
            poster="{{ $poster }}"
        @endif
        preload="{{ $lazy ? 'none' : 'auto' }}"
        {{
            $attributes
                ->except(['src', 'controls', 'lazy', 'item', 'poster', 'width', 'height'])
                ->class(['h-full w-full object-cover'])
        }}
    ></video>
@elseif (curator()->isVideo($item->ext) && filled($poster))
    <img
        src="{{ $poster }}"
        alt="{{ $item->alt ?? '' }}"
        loading="{{ $lazy ? 'lazy' : 'eager' }}"
        {{
            $attributes
                ->except(['src', 'alt', 'lazy', 'item', 'poster', 'width', 'height'])
                ->class([
                    'h-full w-full object-cover' => ! $constrained,
                    'h-full w-full object-contain' => $constrained,
                ])
        }}
    />
@else
    <div
        @class([
            'curator-document-image grid place-items-center w-full h-full text-xs uppercase relative bg-gray-100 dark:bg-gray-900',
            $attributes->get('class')
        ])
        {{ $attributes->except(['src', 'alt', 'lazy', 'item', 'class']) }}
    >
        @if (curator()->isVideo($item->ext))
            @svg('heroicon-o-film', ['class' => 'opacity-20 ' . $iconClasses])
            <span class="block absolute">{{ $item->ext }}</span>
        @elseif (curator()->isAudio($item->ext))
            @svg('heroicon-o-speaker-wave', ['class' => 'opacity-20 ' . $iconClasses])
            <span class="block absolute">{{ $item->ext }}</span>
        @else
            @svg('heroicon-o-document', ['class' => 'opacity-20 ' . $iconClasses])
            <span class="block absolute">{{ $item->ext }}</span>
        @endif
        <span class="sr-only">{{ $item->pretty_name }}</span>
    </div>
@endif
