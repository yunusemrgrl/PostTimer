<?php

return [

    'api_version' => env('INSTAGRAM_API_VERSION', 'v25.0'),

    'rupload_host' => env('INSTAGRAM_RUPLOAD_HOST', 'rupload.facebook.com'),

    /*
     * Business Login for Instagram (Instagram Login) ayarları.
     * App Dashboard > Instagram > API setup with Instagram login >
     * Business login settings bölümünden alınır.
     */
    'client_id' => env('INSTAGRAM_CLIENT_ID'),

    'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),

    'scopes' => [
        'instagram_business_basic',
        'instagram_business_content_publish',
    ],

    'timeout' => (int) env('INSTAGRAM_TIMEOUT', 30),

    'connect_timeout' => (int) env('INSTAGRAM_CONNECT_TIMEOUT', 10),

    'upload_timeout' => (int) env('INSTAGRAM_UPLOAD_TIMEOUT', 600),

    /*
     * Video containers are polled once per minute for at most 5 minutes
     * before the media_publish step, per Meta's recommendation.
     */
    'status_attempts' => (int) env('INSTAGRAM_STATUS_ATTEMPTS', 5),

    'status_sleep' => (int) env('INSTAGRAM_STATUS_SLEEP', 60000),

];
