<?php

namespace App\Support;

use DomainException;

class PaymentStates
{
    public const INTENT_TRANSITIONS = [
        'requires_payment' => ['processing', 'expired', 'cancelled'],
        'processing' => ['requires_payment', 'paid', 'expired', 'needs_review', 'cancelled'],
        'paid' => ['needs_review'],
        'expired' => ['needs_review'],
        'needs_review' => [],
        'cancelled' => ['needs_review'],
    ];

    public const ATTEMPT_TRANSITIONS = [
        'initiating' => ['pending', 'unknown', 'failed'],
        'pending' => ['paid', 'failed', 'expired', 'unknown'],
        'unknown' => ['pending', 'paid', 'failed', 'expired'],
        'failed' => ['paid'],
        'expired' => ['paid'],
        'paid' => ['reversed'],
        'reversed' => [],
    ];

    public static function assertIntentTransition(string $from, string $to): void
    {
        self::assertTransition(self::INTENT_TRANSITIONS, $from, $to);
    }

    public static function assertAttemptTransition(string $from, string $to): void
    {
        self::assertTransition(self::ATTEMPT_TRANSITIONS, $from, $to);
    }

    /** @param array<string, list<string>> $transitions */
    private static function assertTransition(array $transitions, string $from, string $to): void
    {
        if (! isset($transitions[$from]) || ! isset($transitions[$to]) || ($from !== $to && ! in_array($to, $transitions[$from], true))) {
            throw new DomainException('Invalid payment state transition.');
        }
    }
}
