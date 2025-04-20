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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'pawapay' => [
        'api_token' => env('PAWAPAY_API_TOKEN', ''),
        'base_url' => env('PAWAPAY_BASE_URL', 'https://api.sandbox.pawapay.io'),
        'webhook_secret' => env('PAWAPAY_WEBHOOK_SECRET', ''),
        'private_key_path' => env('PAWAPAY_PRIVATE_KEY_PATH', 'app/keys/private_key.pem'),
        'public_key_path' => env('PAWAPAY_PUBLIC_KEY_PATH', 'app/keys/public_key.pem'),
        'pawapay_public_key_path' => env('PAWAPAY_PUBLIC_KEY_PATH', 'app/keys/pawapay_public_key.pem'),
    ],

];
