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
    'otp' => [
        'expose_code' => env('OTP_EXPOSE_CODE', false),
    ],
    'maxchat' => [
        'enabled' => env('MAXCHAT_ENABLED', false),
        'base_url' => env('MAXCHAT_BASE_URL', 'https://core.maxchat.id/walatra-herbal'),
        'api_token' => env('MAXCHAT_API_TOKEN'),
        'timeout' => (int) env('MAXCHAT_TIMEOUT', 30),
        'connect_timeout' => (int) env('MAXCHAT_CONNECT_TIMEOUT', 5),
    ],
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
    ],
    'warehouse' => [
        'enabled' => (bool) env('WAREHOUSE_STOCK_ENABLED', false),
        'base_url' => env('WAREHOUSE_STOCK_BASE_URL'),
        'token' => env('WAREHOUSE_STOCK_TOKEN'),
        'timeout' => (int) env('WAREHOUSE_STOCK_TIMEOUT', 10),
        'paths' => [
            'check' => env('WAREHOUSE_STOCK_CHECK_PATH', '/stock/check'),
            'reserve' => env('WAREHOUSE_STOCK_RESERVE_PATH', '/stock/reserve'),
            'confirm' => env('WAREHOUSE_STOCK_CONFIRM_PATH', '/stock/confirm'),
            'release' => env('WAREHOUSE_STOCK_RELEASE_PATH', '/stock/release'),
        ],
    ],
    'shipping' => [
        'enabled' => (bool) env('SHIPPING_RATE_ENABLED', false),
        'base_url' => env('SHIPPING_RATE_BASE_URL'),
        'token' => env('SHIPPING_RATE_TOKEN'),
        'timeout' => (int) env('SHIPPING_RATE_TIMEOUT', 10),
        'quote_path' => env('SHIPPING_RATE_QUOTE_PATH', '/shipping/quote'),
    ],
    'everpro' => [
        'enabled' => (bool) env('EVERPRO_ENABLED', false),
        'base_url' => env('EVERPRO_BASE_URL', 'https://client-api-sandbox.everpro.id'),
        'client_key' => env('EVERPRO_CLIENT_KEY'),
        'client_secret' => env('EVERPRO_CLIENT_SECRET'),
        'timeout' => (int) env('EVERPRO_TIMEOUT', 10),
        'token_path' => env('EVERPRO_TOKEN_PATH', '/auth/v1/token'),
        'paths' => [
            'provinces' => env('EVERPRO_PROVINCES_PATH', '/location/v1/provinces'),
            'cities' => env('EVERPRO_CITIES_PATH', '/location/v1/cities'),
            'districts' => env('EVERPRO_DISTRICTS_PATH', '/location/v1/districts'),
            'sub_districts' => env('EVERPRO_SUB_DISTRICTS_PATH', '/location/v1/sub-districts'),
            'resolve' => env('EVERPRO_LOCATION_RESOLVE_PATH', '/location/v1/postalcode'),
        ],
    ],

];
