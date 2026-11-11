<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
        'endpoint' => env('BREVO_API_ENDPOINT', 'https://api.brevo.com/v3/smtp/email'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'voice_id' => env('ELEVENLABS_VOICE_ID', 'MIWz5nzn9F2CYPIedIPx'),
        'fallback_voice_id' => env('ELEVENLABS_FALLBACK_VOICE_ID', 'EXAVITQu4vr4xnSDxMaL'),
        'model_id' => env('ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
        'output_format' => env('ELEVENLABS_OUTPUT_FORMAT', 'mp3_44100_128'),
        'stability' => (float) env('ELEVENLABS_STABILITY', 0.45),
        'similarity_boost' => (float) env('ELEVENLABS_SIMILARITY_BOOST', 0.80),
        'style' => (float) env('ELEVENLABS_STYLE', 0.35),
        'cache_dir' => env('ELEVENLABS_CACHE_DIR', storage_path('app/public/ai_voice')),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
