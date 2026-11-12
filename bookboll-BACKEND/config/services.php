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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend'   => [
        'key' => env('RESEND_KEY'),
    ],

    'ses'      => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack'    => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe'   => [
        'key'            => env('STRIPE_PUBLIC_KEY'),
        'secret'         => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    | Two OneSignal apps: the admin (business) app and the customer app. Apple
    | binds an APNs configuration to one bundle id, so each mobile app needs its
    | own OneSignal app, and a push only reaches a device through the app that
    | registered it. The legacy top-level keys stay as aliases of `admin` for any
    | code or deployment still reading them.
    */
    'onesignal' => [
        'app_id'       => env('ONESIGNAL_APP_ID'),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
        'apps' => [
            'admin' => [
                'app_id'       => env('ONESIGNAL_APP_ID'),
                'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
            ],
            'customer' => [
                'app_id'       => env('ONESIGNAL_CUSTOMER_APP_ID'),
                'rest_api_key' => env('ONESIGNAL_CUSTOMER_REST_API_KEY'),
            ],
        ],
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'enabled' => env('TURNSTILE_ENABLED'),
    ],

    'google' => [
        // One OAuth client serves every tenant storefront. The browser never sees it:
        // the broker (App\Services\OAuthBrokerService) runs the whole code exchange
        // server-side, which is what lets *.bookboll.com and paid custom domains sign
        // in with Google without each one being registered here. Google only ever
        // needs the single redirect URI below.
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/api/auth/google/callback'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', 'com.bookboll.app'),
    ],


    'whatsapp' => [
        'comeback_template' => env('WHATSAPP_COMEBACK_TEMPLATE'),
        'comeback_language' => env('WHATSAPP_COMEBACK_LANGUAGE', 'en_US'),
        // Meta Graph API version and Bookboll's own access token. A token typed into
        // the WhatsApp settings page wins; this is the fallback (and the only place a
        // platform token needs to live in production).
        'graph_url'    => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com/v25.0'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret'   => env('META_APP_SECRET'),
        // The Meta APP id — template header images are uploaded against the app.
        'app_id'       => env('META_APP_ID'),
    ],

];
