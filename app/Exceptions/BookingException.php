<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain conflict rendered as HTTP 409 with a stable machine-readable `code`.
 *
 * Codes: quota_unavailable, insufficient_capacity, booking_window_closed, visit_date_too_close,
 * booking_not_pending, booking_not_cancellable, idempotency_conflict, idempotency_key_expired,
 * idempotency_key_abandoned, intent_already_completed, category_in_use, invalid_price.
 */
class BookingException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'conflict', public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
