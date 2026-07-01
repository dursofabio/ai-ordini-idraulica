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

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'model_fast' => env('ANTHROPIC_MODEL_FAST', 'claude-3-5-haiku-latest'),
        'model_smart' => env('ANTHROPIC_MODEL_SMART', 'claude-sonnet-4-5'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'timeout' => env('ANTHROPIC_TIMEOUT', 120),
        'retry_times' => env('ANTHROPIC_RETRY_TIMES', 2),
        'retry_delay_ms' => env('ANTHROPIC_RETRY_DELAY_MS', 2000),
    ],

    'embedding' => [
        'base_url' => env('EMBEDDING_BASE_URL', 'http://localhost:11434'),
        'model' => env('EMBEDDING_MODEL', 'bge-m3'),
        'api_key' => env('EMBEDDING_API_KEY'),
        'dimensions' => env('EMBEDDING_DIM', 1024),
        'timeout' => env('EMBEDDING_TIMEOUT', 120),
        'retry_times' => env('EMBEDDING_RETRY_TIMES', 2),
        'retry_delay_ms' => env('EMBEDDING_RETRY_DELAY_MS', 2000),
    ],

];
