<?php

return [
    'claim_mode' => env('APPROVAL_CLAIM_MODE', 'compat'),
    'claim_minutes' => 15,
    'payment_preview_enabled' => (bool) env('PAYMENT_PREVIEW_ENABLED', false),
    'demo_payments_enabled' => (bool) env('DEMO_PAYMENTS_ENABLED', false),
    // TODO[DOKU]: enable only with verified provider callbacks, booking locks and reconciliation in Phase 1B.
    'doku_enabled' => false,
];
