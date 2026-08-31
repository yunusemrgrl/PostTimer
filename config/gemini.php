<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Gemini API
    |--------------------------------------------------------------------------
    |
    | Video analizi + çeviri entegrasyonu. gemini-3.6-flash videoyu doğrudan
    | input olarak alır; konuşmayı transkript eder, ekrandaki yazıları okur
    | ve hedef dile çevirir (timestamp'li JSON çıktısı).
    |
    */

    'api_key' => env('GEMINI_API_KEY'),

    'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),

    'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),

    // Tahmini maliyet: USD / video saniyesi (≈833 tok/s × $0.30/1M token).
    // Fiyat güncellemesi deploy gerektirmez.
    'cost_per_video_second' => (float) env('GEMINI_COST_PER_VIDEO_SECOND', 0.000250),

    /*
    | Video indirme + generateContent çağrısı tek HTTP isteğinde bittiği
    | için timeout toplam süreyi kapsar. Uzun videolarda artırılabilir.
    */
    'timeout' => (int) env('GEMINI_TIMEOUT', 300),

];
