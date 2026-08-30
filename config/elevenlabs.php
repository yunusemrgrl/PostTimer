<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ElevenLabs API
    |--------------------------------------------------------------------------
    |
    | Sadece Text-to-Speech kullanılır (dubbing endpoint'i KALDIRILDI).
    | Ses, voice_id ile seçilir — UI'da ses seçtirilmez; değiştirmek
    | istendiğinde ELEVENLABS_VOICE_ID güncellenir.
    |
    */

    'api_key' => env('ELEVENLABS_API_KEY'),

    'base_url' => env('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),

    'tts' => [
        // Varsayılan ses: settings değişikliğiyle değiştirilir.
        'voice_id' => env('ELEVENLABS_VOICE_ID'),

        // Varsayılan model: eleven_flash_v2_5 — multilingual_v2'ye göre ~5x daha
        // ucuz (≈$0.06 - $0.11 / 1K karakter), 32 dili destekler, kısa reels
        // dublaji için yeterli kalite. Yüksek kaliteli uzun içerik gerektiğinde
        // ELEVENLABS_TTS_MODEL=eleven_multilingual_v2 ile geçilebilir.
        'model_id' => env('ELEVENLABS_TTS_MODEL', 'eleven_flash_v2_5'),

        // Düşük bitrate MP3 → daha az bant genişliği + hızlı teslim; telefon
        // hoparlöründe dublaj için yeterli. Yüksek çözünürlük istersen
        // mp3_44100_192 veya mp3_44100_320 kullanılabilir.
        'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', 'mp3_22050_64'),

        // Tahmini maliyet (USD / 1K karakter) — EstimateLocalizationCost action'ı
        // kullanır. Fiyat güncellemesi deploy gerektirmez.
        'cost_per_1k_flash' => (float) env('ELEVENLABS_COST_PER_1K_FLASH', 0.06),
        'cost_per_1k_multilingual' => (float) env('ELEVENLABS_COST_PER_1K_MULTILINGUAL', 0.22),
    ],

    'timeout' => (int) env('ELEVENLABS_TIMEOUT', 120),

];
