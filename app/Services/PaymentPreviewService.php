<?php

namespace App\Services;

use App\Models\User;

class PaymentPreviewService
{
    public function __construct(private PaymentService $payments) {}

    public function show(User $owner, string $guid): array
    {
        abort_unless(config('operations.payment_preview_enabled'), 404);
        $booking = $this->payments->ownerBooking($owner, $guid);

        return ['booking_guid' => $guid, 'availability' => $booking->status === 'awaiting_payment' ? 'preview_only' : 'unavailable',
            'reason' => $booking->status === 'awaiting_payment' ? null : 'booking_not_awaiting_payment',
            'provider' => 'doku', 'integration_status' => 'not_configured', 'can_pay' => false,
            'payment_status' => 'unverified', 'presentation_styles' => ['qris', 'virtual_account', 'ewallet']];
    }
}
