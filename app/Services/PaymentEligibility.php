<?php

namespace App\Services;

class PaymentEligibility
{
    /** Replace this binding with the authoritative DOKU adapter; its writes must lock the parent booking. */
    public function status(object $booking): string
    {
        return 'unverified';
    }
}
