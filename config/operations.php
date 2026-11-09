<?php

return [
    'claim_mode' => env('APPROVAL_CLAIM_MODE', 'compat'),
    'claim_minutes' => 15,
    'payment_preview_enabled' => (bool) env('PAYMENT_PREVIEW_ENABLED', false),
    'demo_payments_enabled' => (bool) env('DEMO_PAYMENTS_ENABLED', false),
    // Legacy flag retained for callers; DOKU rollout is controlled by config/doku.php.
    'doku_enabled' => false,
    // HTTPS origin for the ticket-page link in the "tickets ready" email ({origin}/tickets/{booking}). The link opens the app and
    // carries no credential; authentication and booking ownership are enforced by GET /api/v1/bookings/{booking}/tickets.
    'ticket_link_base_url' => env('TICKET_LINK_BASE_URL'),
];
