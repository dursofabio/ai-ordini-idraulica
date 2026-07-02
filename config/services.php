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

        /*
        |----------------------------------------------------------------
        | Pricing, spend cap, and enrichment cache TTL
        |----------------------------------------------------------------
        |
        | Per-model-role pricing (USD per million tokens) used by
        | AiSpendGuard to estimate the cost of a classification batch
        | before/after calling the API. Models are matched to their price
        | by comparing the model name against `model_fast`/`model_smart`
        | above, so a single price applies regardless of which concrete
        | model string is configured for that role.
        | `batch_cost_cap` is the maximum USD spend allowed per
        | ClassificationBatchDispatcher run (shared across all jobs
        | dispatched by that run); null disables the cap.
        | `enrichment_cache_ttl` is the TTL in seconds for cached
        | classification results keyed by description_raw hash; null means
        | cached forever (no expiration).
        |
        */
        'pricing' => [
            'model_fast' => [
                'input_per_million' => env('ANTHROPIC_PRICE_FAST_INPUT', 0.8),
                'output_per_million' => env('ANTHROPIC_PRICE_FAST_OUTPUT', 4.0),
            ],
            'model_smart' => [
                'input_per_million' => env('ANTHROPIC_PRICE_SMART_INPUT', 3.0),
                'output_per_million' => env('ANTHROPIC_PRICE_SMART_OUTPUT', 15.0),
            ],
        ],
        'batch_cost_cap' => env('ANTHROPIC_BATCH_COST_CAP'),
        'enrichment_cache_ttl' => env('ANTHROPIC_ENRICHMENT_CACHE_TTL'),
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
