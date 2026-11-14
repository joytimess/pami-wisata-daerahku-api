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

    /**
     * The one absolute payment deadline of a Midtrans payment cycle. It is counted from the moment the customer picks the first
     * method (not from approval); switching method or retrying inside the cycle never extends it.
     */
    public const PAYMENT_WINDOW_SECONDS = 15 * 60;

    /** After approval the customer has this long to pick a method; the booking then expires and its quota is released. */
    public const PAYMENT_SELECTION_WINDOW_SECONDS = 24 * 3600;

    /** The approval window that DOKU and DEMO always had; kept unchanged for them because the 15 minute rule is Midtrans policy. */
    public const LEGACY_PAYMENT_WINDOW_SECONDS = 60 * 60;

    /**
     * Extra time after the deadline in which an unresolved payment stays under INTERNAL verification before it is escalated to
     * review. It is never offered to the customer: can_pay, can_create, Charge and retry all stop at the deadline itself.
     */
    public const PAYMENT_GRACE_SECONDS = 15 * 60;

    public static function paymentWindowSeconds(string $activeProvider): int
    {
        return $activeProvider === 'midtrans' ? self::PAYMENT_WINDOW_SECONDS : self::LEGACY_PAYMENT_WINDOW_SECONDS;
    }

    /** Absolute payment deadline for a cycle that starts at $startedAt; it never passes the visit start. */
    public static function paymentDeadlineFrom(CarbonInterface $startedAt, CarbonImmutable $visitStart, string $activeProvider): CarbonImmutable
    {
        return CarbonImmutable::instance($startedAt)->utc()->addSeconds(self::paymentWindowSeconds($activeProvider))->min($visitStart);
    }

    /** Last moment a payment method can be chosen after approval. */
    public static function paymentSelectionDeadline(CarbonInterface $approvedAt, CarbonImmutable $visitStart): CarbonImmutable
    {
        return CarbonImmutable::instance($approvedAt)->utc()->addSeconds(self::PAYMENT_SELECTION_WINDOW_SECONDS)->min($visitStart);
    }

    /** Booking snapshot is the only timezone source for an existing booking; the live destination value only backfills old rows. */
    public static function bookingTimezone(object $booking): string
    {
        return $booking->destination_timezone_snapshot ?? $booking->destination_timezone;
    }

    public static function visitStart(string $visitDate, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(substr($visitDate, 0, 10), $timezone)->startOfDay()->utc();
    }

    public static function tooCloseToCreate(CarbonInterface $now, CarbonImmutable $visitStart): bool
    {
        return $now->copy()->addMinutes(self::CREATE_LEAD_MINUTES)->gte($visitStart);
    }
}
