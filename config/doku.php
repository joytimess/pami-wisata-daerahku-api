<?php

return [
    'enabled' => (bool) env('DOKU_ENABLED', false),
    'environment' => env('DOKU_ENVIRONMENT', 'sandbox'),
    'client_id' => env('DOKU_CLIENT_ID', ''),
    'secret_key' => env('DOKU_SECRET_KEY', ''),
    'merchant_private_key_path' => env('DOKU_MERCHANT_PRIVATE_KEY_PATH', ''),
    'merchant_private_key_passphrase' => env('DOKU_MERCHANT_PRIVATE_KEY_PASSPHRASE', ''),
    'partner_service_id' => env('DOKU_BNI_PARTNER_SERVICE_ID', ''),
    'customer_prefix' => env('DOKU_BNI_CUSTOMER_PREFIX', ''),
    // Bank-specific VA merchant values. BNI keeps the legacy keys above; each bank is gated independently.
    // notification_channel_id is the CHANNEL-ID header DOKU sends on that bank's notifications (confirm from a Sandbox capture).
    'va_banks' => [
        'bca' => ['partner_service_id' => env('DOKU_BCA_PARTNER_SERVICE_ID', ''), 'customer_prefix' => env('DOKU_BCA_CUSTOMER_PREFIX', ''),
            'notification_channel_id' => env('DOKU_BCA_NOTIFICATION_CHANNEL_ID', '')],
        'bri' => ['partner_service_id' => env('DOKU_BRI_PARTNER_SERVICE_ID', ''), 'customer_prefix' => env('DOKU_BRI_CUSTOMER_PREFIX', ''),
            'notification_channel_id' => env('DOKU_BRI_NOTIFICATION_CHANNEL_ID', '')],
        'mandiri' => ['partner_service_id' => env('DOKU_MANDIRI_PARTNER_SERVICE_ID', ''), 'customer_prefix' => env('DOKU_MANDIRI_CUSTOMER_PREFIX', ''),
            'notification_channel_id' => env('DOKU_MANDIRI_NOTIFICATION_CHANNEL_ID', '')],
    ],
    'webhook_url' => env('DOKU_WEBHOOK_URL', ''),
    'channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOKU_CHANNELS', ''))))),
    // Channels the operator has confirmed against the real merchant account. Required for every channel except the
    // grandfathered BNI VA. Never a substitute for provider activation evidence.
    'verified_channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOKU_VERIFIED_CHANNELS', ''))))),
    'production_approved' => (bool) env('DOKU_PRODUCTION_APPROVED', false),
    'http_timeout_seconds' => (int) env('DOKU_HTTP_TIMEOUT_SECONDS', 15),
    'reconciliation_enabled' => (bool) env('DOKU_RECONCILIATION_ENABLED', true),
];
