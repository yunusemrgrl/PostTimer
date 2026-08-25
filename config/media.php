<?php

return [

    /*
     * Video thumbnail üretimi için FFmpeg binary'si. MediaObserver,
     * ffmpeg'in sistemde mevcut olup olmadığını bu isimle sorgular
     * (where/which) ve üretim komutunu bu binary ile kurar.
     */
    'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),

    /*
     * MCP endpoint token kapısı. MCP_TOKEN set edilirse /mcp/* istekleri
     * X-Mcp-Token header'ıyla eşleşmek zorunda; boşsa (local) serbest.
     */
    'mcp_token' => env('MCP_TOKEN'),

];
