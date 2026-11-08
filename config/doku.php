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
    'webhook_url' => env('DOKU_WEBHOOK_URL', ''),
    'channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOKU_CHANNELS', ''))))),
    'production_approved' => (bool) env('DOKU_PRODUCTION_APPROVED', false),
    'http_timeout_seconds' => (int) env('DOKU_HTTP_TIMEOUT_SECONDS', 15),
    'reconciliation_enabled' => (bool) env('DOKU_RECONCILIATION_ENABLED', true),
];
