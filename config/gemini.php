<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Gemini API
    |--------------------------------------------------------------------------
    |
    | Video analizi + çeviri entegrasyonu. gemini-2.5-flash videoyu doğrudan
    | input olarak alır; konuşmayı transkript eder, ekrandaki yazıları okur
    | ve hedef dile çevirir (timestamp'li JSON çıktısı).
    |
    */

    'api_key' => env('GEMINI_API_KEY'),

    'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),

    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

    /*
    | Video indirme + generateContent çağrısı tek HTTP isteğinde bittiği
    | için timeout toplam süreyi kapsar. Uzun videolarda artırılabilir.
    */
    'timeout' => (int) env('GEMINI_TIMEOUT', 300),

];
