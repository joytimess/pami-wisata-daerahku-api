<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Booking lifecycle rules shared by services so quota math never diverges. */
final class BookingRules
{
    /** Statuses that still hold capacity. */
    public const HELD = ['pending_approval', 'awaiting_payment', 'payment_verification', 'payment_review', 'confirmed'];

    public const STATUSES = ['pending_approval', 'awaiting_payment', 'payment_verification', 'payment_review', 'confirmed', 'rejected', 'expired', 'cancelled'];

    /** Booking creation requires strictly more than this lead time before the visit day starts. */
    public const CREATE_LEAD_MINUTES = 25 * 60 + 15;

    public const APPROVE_LEAD_MINUTES = 60 + 15;

    public const IDEMPOTENCY_DAYS = 7;

    public static function visitStart(string $visitDate, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(substr($visitDate, 0, 10), $timezone)->startOfDay()->utc();
    }

    public static function tooCloseToCreate(CarbonInterface $now, CarbonImmutable $visitStart): bool
    {
        return $now->copy()->addMinutes(self::CREATE_LEAD_MINUTES)->gte($visitStart);
    }
}
