<?php

namespace App\Services;

class PaymentEligibility
{
    /** TODO[DOKU]: bind verified provider eligibility in Phase 1B; payment writes must lock the parent booking. */
    public function status(object $booking): string
    {
        return 'unverified';
    }
}
