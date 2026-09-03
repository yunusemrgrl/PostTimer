@php
    /**
     * Canlı Instagram önizlemesi.
     *
     * Form state'i Livewire tarafında `data.*` altında yaşar; bu panel
     * yalnızca OKUR (entangle). Hiçbir alana yazmaz, validation ve
     * dehydrate akışını etkilemez. Curator picker state'i uuid anahtarlı
     * medya dizileridir; her öğede `url` ve `ext` bulunur.
     */
@endphp

<div
    x-data="{
        caption: $wire.entangle('data.caption'),
        surface: $wire.entangle('data.surface'),
        type: $wire.entangle('data.type'),
        storyLink: $wire.entangle('data.story_link'),
        firstComment: $wire.entangle('data.first_comment'),
        media: $wire.entangle('data.media_url'),
        carousel: $wire.entangle('data.carousel_media'),

        surfaceLabels: { FEED: 'Feed', REELS: 'Reels', STORY: 'Hikaye' },

        get items() {
            const source = this.type === 'CAROUSEL_ALBUM' ? this.carousel : this.media;

            return Object.values(source ?? {}).filter((item) => item && item.url);
        },

        get isVideo() {
            const first = this.items[0];

            return first ? ['mp4', 'mov', 'webm'].includes((first.ext ?? '').toLowerCase()) : false;
        },
    }"
    class="sticky top-6 space-y-3"
>
    <div class="flex items-center justify-between px-1">
        <h3 class="text-sm font-bold tracking-wide text-gray-950 dark:text-white">
            Canlı Önizleme
        </h3>
        <span
            class="inline-flex items-center rounded-full border-2 border-gray-950 bg-amber-200 px-2.5 py-0.5 text-xs font-bold text-gray-950 dark:border-white/60"
            x-text="surfaceLabels[surface] ?? 'Feed'"
        ></span>
    </div>

    {{-- Telefon çerçevesi --}}
    <div class="rounded-[1.75rem] border-2 border-gray-950 bg-white shadow-[6px_6px_0_0_rgb(9_22_29)] dark:border-white/70 dark:bg-gray-950 dark:shadow-[6px_6px_0_0_rgba(255,255,255,0.25)]">
        {{-- Hikaye düzeni --}}
        <template x-if="surface === 'STORY'">
            <div class="relative overflow-hidden rounded-[1.6rem]">
                <div class="flex aspect-[9/16] items-center justify-center bg-gray-100 dark:bg-gray-900">
                    <template x-if="items.length">
                        <img :src="items[0].url" alt="" class="h-full w-full object-cover" />
                    </template>
                    <template x-if="! items.length">
                        <div class="flex flex-col items-center gap-2 text-gray-400">
                            <x-filament::icon icon="heroicon-o-photo" class="size-10" />
                            <span class="text-xs font-medium">Medya seçilmedi</span>
                        </div>
                    </template>
                </div>

                <template x-if="storyLink">
                    <div class="absolute inset-x-6 bottom-10 flex justify-center">
                        <span
                            class="max-w-full truncate rounded-full bg-white/95 px-4 py-2 text-xs font-semibold text-gray-900 shadow-md"
                            x-text="'🔗 ' + storyLink"
                        ></span>
                    </div>
                </template>
            </div>
        </template>

        {{-- Feed / Reels düzeni --}}
        <template x-if="surface !== 'STORY'">
            <div class="p-3">
                <div class="flex items-center gap-2 px-1 pb-2">
                    <span class="size-7 rounded-full bg-gradient-to-tr from-amber-300 via-rose-400 to-teal-500 ring-2 ring-gray-950/10"></span>
                    <span class="text-xs font-bold text-gray-900 dark:text-gray-100">@@hesabınız</span>
                    <span class="ml-auto text-gray-400">···</span>
                </div>

                <div class="overflow-hidden rounded-xl bg-gray-100 dark:bg-gray-900" :class="surface === 'REELS' ? 'aspect-[4/5]' : 'aspect-square'">
                    <template x-if="items.length > 1">
                        <div class="flex h-full snap-x snap-mandatory overflow-x-auto">
                            <template x-for="item in items" :key="item.id ?? item.url">
                                <img :src="item.url" alt="" class="h-full w-full shrink-0 snap-center object-cover" />
                            </template>
                        </div>
                    </template>
                    <template x-if="items.length === 1 && isVideo">
                        <video :src="items[0].url" class="h-full w-full object-cover" muted playsinline></video>
                    </template>
                    <template x-if="items.length === 1 && ! isVideo">
                        <img :src="items[0].url" alt="" class="h-full w-full object-cover" />
                    </template>
                    <template x-if="! items.length">
                        <div class="flex h-full flex-col items-center justify-center gap-2 text-gray-400">
                            <x-filament::icon icon="heroicon-o-photo" class="size-10" />
                            <span class="text-xs font-medium">Medya seçilmedi</span>
                        </div>
                    </template>
                </div>

                <template x-if="type === 'CAROUSEL_ALBUM' && items.length > 1">
                    <div class="flex justify-center gap-1 pt-2">
                        <template x-for="(item, index) in items" :key="index">
                            <span class="size-1.5 rounded-full" :class="index === 0 ? 'bg-teal-600' : 'bg-gray-300'"></span>
                        </template>
                    </div>
                </template>

                <div class="flex items-center gap-3 px-1 pt-3 text-gray-800 dark:text-gray-200">
                    <x-filament::icon icon="heroicon-o-heart" class="size-5" />
                    <x-filament::icon icon="heroicon-o-chat-bubble-oval-left" class="size-5" />
                    <x-filament::icon icon="heroicon-o-paper-airplane" class="size-5" />
                    <x-filament::icon icon="heroicon-o-bookmark" class="ml-auto size-5" />
                </div>

                <p class="px-1 pt-2 text-xs leading-relaxed text-gray-700 dark:text-gray-300">
                    <span class="font-bold text-gray-900 dark:text-gray-100">@@hesabınız</span>
                    <span x-text="caption || 'Açıklama burada görünecek…'" :class="caption ? '' : 'italic text-gray-400'"></span>
                </p>

                <template x-if="firstComment">
                    <p class="px-1 pt-1 text-xs text-gray-500 dark:text-gray-400">
                        <span class="font-semibold">@@hesabınız</span>
                        <span x-text="firstComment"></span>
                    </p>
                </template>
            </div>
        </template>
    </div>

    <p class="px-1 text-center text-xs text-gray-400">
        Bu panel form state'ini anlık yansıtır; kaydedilen değerleri değiştirmez.
    </p>
</div>
