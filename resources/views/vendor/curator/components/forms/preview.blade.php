@php
    $record = $getRecord();
@endphp

<x-curator::display
    :item="$record"
    :src="$record?->large_url"
    :poster="$record?->thumbnail_url"
    :controls="true"
    :player="curator()->isVideo($record?->ext)"
    icon-classes="h-24"
    class="h-full w-full object-cover"
/>
