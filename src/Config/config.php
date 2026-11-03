<?php

return [
    /*
    | Admin pages live under /{me_prefix()}/{admin_path}. Public booking pages under /{public_path}.
    */
    'admin_path' => env('ABS_ADMIN_PATH', 'booking'),
    'public_path' => env('ABS_PUBLIC_PATH', 'book'),
    // Customer panel (login, register, my bookings, profile).
    'account_path' => env('ABS_ACCOUNT_PATH', 'account'),

    'default_timezone' => env('ABS_DEFAULT_TIMEZONE', 'UTC'),
    'default_currency' => env('ABS_DEFAULT_CURRENCY', 'USD'),

    /*
    | Public booking rate limits (per IP, per minute).
    */
    'rate_limits' => [
        'slots' => (int) env('ABS_RATE_LIMIT_SLOTS', 60),
        'booking' => (int) env('ABS_RATE_LIMIT_BOOKING', 10),
    ],

    /*
    | Online payment gateway used when a business accepts online payments. "stripe", "paypal" or null (none).
    */
    'gateway' => env('ABS_PAYMENT_GATEWAY'),

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'api_base' => env('STRIPE_API_BASE', 'https://api.stripe.com'),
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    | Also send booking SMS through metheme's SMS gateway (SMS Configuration page). Mail is always sent.
    */
    'sms_notifications' => (bool) env('ABS_SMS_NOTIFICATIONS', false),

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        // Sandbox: https://api-m.sandbox.paypal.com
        'api_base' => env('PAYPAL_API_BASE', 'https://api-m.paypal.com'),
    ],

    /*
    | Booking lock retries when MySQL reports a deadlock or lock wait timeout.
    */
    'transaction_attempts' => 3,
];
