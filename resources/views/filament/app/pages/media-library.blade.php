<x-filament-panels::page>
    <div
        x-data="{ dragging: false }"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="
            dragging = false;
            $refs.fileInput.files = $event.dataTransfer.files;
            $refs.fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        "
        class="space-y-6"
    >
        {{-- Sürükle-bırak yükleme alanı --}}
        <label
            for="media-upload"
            class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed px-6 py-12 text-center transition-colors"
            :class="dragging
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/30'
                : 'border-gray-300 bg-gray-50 hover:border-primary-400 dark:border-gray-700 dark:bg-gray-900'"
        >
            <span class="flex size-12 items-center justify-center rounded-xl border-2 border-gray-950 bg-primary-100 text-gray-950 shadow-[3px_3px_0_0_rgb(9_22_29)] dark:border-white/60 dark:bg-primary-900 dark:text-white dark:shadow-none">
                <x-filament::icon icon="heroicon-o-arrow-up-tray" class="size-6" />
            </span>

            <span class="text-sm font-bold text-gray-900 dark:text-gray-100">
                Dosyaları buraya sürükleyin ya da seçmek için tıklayın
            </span>
            <span class="text-xs text-gray-400">JPEG · PNG · GIF · WebP · MP4 · MOV · WebM — en fazla 100 MB</span>

            <input
                id="media-upload"
                x-ref="fileInput"
                type="file"
                wire:model="uploads"
                multiple
                accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm"
                class="hidden"
            />
        </label>

        <div wire:loading wire:target="uploads" class="flex items-center gap-2 text-sm font-medium text-primary-600">
            <x-filament::loading-indicator class="size-5" />
            Yükleniyor…
        </div>

        @error('uploads.*')
            <p class="text-sm text-danger-600">{{ $message }}</p>
        @enderror

        {{-- Sekmeler + arama --}}
        <div class="flex flex-wrap items-center gap-3">
            @php
                $tabs = [
                    'all' => 'Tümü',
                    'images' => 'Görseller',
                    'videos' => 'Videolar',
                ];
            @endphp

            <div class="flex gap-2">
                @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        wire:click="$set('tab', '{{ $key }}')"
                        @class([
                            'rounded-full border-2 border-gray-950 px-4 py-1.5 text-sm font-bold transition-all dark:border-white/60',
                            'bg-amber-200 text-gray-950 shadow-[2px_2px_0_0_rgb(9_22_29)] dark:text-gray-950' => $tab === $key,
                            'bg-white text-gray-500 hover:text-gray-950 dark:bg-gray-900 dark:text-gray-400' => $tab !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="relative ml-auto w-full max-w-xs">
                <x-filament::icon icon="heroicon-o-magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                <input
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Medya ara…"
                    class="w-full rounded-full border-2 border-gray-950 bg-white py-2 pl-9 pr-4 text-sm font-medium text-gray-900 placeholder-gray-400 focus:border-primary-500 focus:outline-none dark:border-white/60 dark:bg-gray-900 dark:text-white"
                />
            </div>
        </div>

        {{-- Kart grid'i --}}
        @php /** @var \Illuminate\Contracts\Pagination\Paginator $media */ @endphp

        @if ($this->media->isEmpty())
            <div class="flex flex-col items-center gap-3 rounded-2xl border-2 border-dashed border-gray-200 py-16 text-center dark:border-gray-800">
                <x-filament::icon icon="heroicon-o-photo" class="size-12 text-gray-300" />
                <p class="text-sm font-medium text-gray-500">
                    {{ $search !== '' || $tab !== 'all' ? 'Bu filtreyle eşleşen medya yok.' : 'Henüz medya yok — ilk dosyanızı yukarıdan yükleyin.' }}
                </p>
            </div>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @foreach ($this->media as $item)
                    @php
                        /** @var \App\Models\Media $item */
                        $isVideo = curator()->isVideo($item->ext);
                        $thumb = $isVideo ? $item->thumbnail_url : $item->medium_url;
                    @endphp

                    <div
                        wire:key="media-{{ $item->getKey() }}"
                        class="group relative aspect-square overflow-hidden rounded-xl border-2 border-gray-950/10 bg-gray-100 transition-all hover:border-gray-950 hover:shadow-[4px_4px_0_0_rgb(9_22_29)] dark:border-white/10 dark:bg-gray-900 dark:hover:border-white/60 dark:hover:shadow-[4px_4px_0_0_rgba(255,255,255,0.2)]"
                        @if ($isVideo && blank($item->curations['video_thumbnail'] ?? null))
                            x-data="{ mediaName: @js($item->name), videoUrl: @js($item->url) }"
                            x-init="window.generateVideoThumbnail?.($el, mediaName, videoUrl)"
                        @endif
                    >
                        @if ($thumb)
                            <img src="{{ $thumb }}" alt="{{ $item->alt ?? $item->title }}" loading="lazy" class="h-full w-full object-cover" />
                        @elseif ($isVideo)
                            <video src="{{ $item->url }}" class="h-full w-full object-cover" muted preload="metadata"></video>
                        @else
                            <div class="flex h-full items-center justify-center text-gray-300">
                                <x-filament::icon icon="heroicon-o-document" class="size-10" />
                            </div>
                        @endif

                        @if ($isVideo)
                            <span class="absolute right-2 top-2 flex size-7 items-center justify-center rounded-full bg-gray-950/70 text-white">
                                <x-filament::icon icon="heroicon-o-play" class="size-4" />
                            </span>
                        @endif

                        {{-- Hover overlay: ad + boyut + sil --}}
                        <div class="absolute inset-x-0 bottom-0 flex translate-y-full items-center gap-2 bg-gray-950/85 px-3 py-2 text-white transition-transform duration-150 group-hover:translate-y-0">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-bold">{{ $item->title ?? $item->name }}</p>
                                <p class="text-[10px] text-gray-300">{{ \Illuminate\Support\Number::fileSize((int) $item->size) }}</p>
                            </div>

                            <button
                                type="button"
                                wire:click="deleteMedia({{ $item->getKey() }})"
                                wire:confirm="Bu medya kalıcı olarak silinsin mi?"
                                class="rounded-md p-1 text-gray-300 transition-colors hover:bg-danger-500 hover:text-white"
                                title="Sil"
                            >
                                <x-filament::icon icon="heroicon-o-trash" class="size-4" />
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pt-2">
                {{ $this->media->links() }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
