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

    /**
     * Exits from `needs_review`. The payment engine can never take them (it only ever enters review); only an authorized operator
     * recovery whose evidence the server verified itself may.
     */
    public const OPERATOR_INTENT_TRANSITIONS = [
        'needs_review' => ['paid', 'requires_payment'],
    ];

    public static function assertIntentTransition(string $from, string $to): void
    {
        self::assertTransition(self::INTENT_TRANSITIONS, $from, $to);
    }

    public static function assertOperatorIntentTransition(string $from, string $to): void
    {
        if (! in_array($to, self::OPERATOR_INTENT_TRANSITIONS[$from] ?? [], true)) {
            throw new DomainException('Invalid operator payment state transition.');
        }
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
