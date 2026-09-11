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

];
