<?php

return [
    // Kill switch for NEW Charge only. Status lookup, notifications and reconciliation of orders the provider already
    // accepted must keep working when this is false (later phases).
    'enabled' => (bool) env('MIDTRANS_ENABLED', false),
    // sandbox|production. Anything else is treated as not configured.
    'environment' => env('MIDTRANS_ENVIRONMENT', 'sandbox'),
    // Backend secret only. Never sent to Flutter, logged, or put into fixtures.
    'server_key' => env('MIDTRANS_SERVER_KEY', ''),
    // Expected merchant identity; mandatory in production, verified against provider responses when present.
    'merchant_id' => env('MIDTRANS_MERCHANT_ID', ''),
    // Internal registry codes (va_bca, va_bni, qris). An allowlist, never proof of provider activation.
    'channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('MIDTRANS_CHANNELS', ''))))),
    // Production only: channels the operator has verified against the activated merchant account.
    'verified_channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('MIDTRANS_VERIFIED_CHANNELS', ''))))),
    'production_approved' => (bool) env('MIDTRANS_PRODUCTION_APPROVED', false),
    // Core API standard hosts, pinned per environment. A host that does not match its environment fails closed.
    'base_urls' => [
        'sandbox' => 'https://api.sandbox.midtrans.com',
        'production' => 'https://api.midtrans.com',
    ],
    'http_timeout_seconds' => (int) env('MIDTRANS_HTTP_TIMEOUT_SECONDS', 10),
    // Initial technical choice above Midtrans' documented 20 second minimum; to be proven by Sandbox UAT.
    'minimum_expiry_seconds' => (int) env('MIDTRANS_MINIMUM_EXPIRY_SECONDS', 30),
    // QRIS acquirer active on the merchant account.
    'qris_acquirer' => env('MIDTRANS_QRIS_ACQUIRER', 'gopay'),
];
