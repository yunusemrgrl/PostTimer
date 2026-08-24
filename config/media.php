<?php

return [

    /*
     * Video thumbnail üretimi için FFmpeg binary'si. MediaObserver,
     * ffmpeg'in sistemde mevcut olup olmadığını bu isimle sorgular
     * (where/which) ve üretim komutunu bu binary ile kurar.
     */
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),

];
