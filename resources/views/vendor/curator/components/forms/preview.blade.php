@php
    $record = $getRecord();
@endphp

<x-curator::display
    :item="$record"
    :src="$record?->url"
    :player="curator()->isVideo($record?->ext)"
    :controls="curator()->isVideo($record?->ext)"
    icon-classes="h-24"
/>
