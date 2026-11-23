<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    | Product search used by the wishlist. "fake" is a sample catalog that only works in local and testing;
    | any other value (or none) leaves the search unavailable until a real provider is wired in.
    */
    'product_search' => [
        'driver' => env('PRODUCT_SEARCH_DRIVER', 'fake'),
        // Most credits this app may spend with the provider; keep it below the free trial so it is never exceeded.
        'credit_limit' => (int) env('PRODUCT_SEARCH_CREDIT_LIMIT', 2000),
        'serper' => [
            'key' => env('SERPER_API_KEY'),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
