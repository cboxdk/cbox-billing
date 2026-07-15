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

    /*
    |--------------------------------------------------------------------------
    | Cbox ID — OIDC identity provider
    |--------------------------------------------------------------------------
    |
    | cbox-billing authenticates its users against a running Cbox ID instance as
    | a standard OIDC relying party (authorization-code + PKCE). Endpoints are
    | discovered from `{issuer}/.well-known/openid-configuration` — never hard-
    | coded. Leave the issuer empty for local/demo mode (no live provider needed).
    |
    */
    'cbox_id' => [
        'issuer' => env('CBOX_ID_ISSUER'),
        'client_id' => env('CBOX_ID_CLIENT_ID'),
        'client_secret' => env('CBOX_ID_CLIENT_SECRET'),
        'redirect' => env('CBOX_ID_REDIRECT_URI'),
        'scopes' => env('CBOX_ID_SCOPES', 'openid profile email'),
    ],

];
