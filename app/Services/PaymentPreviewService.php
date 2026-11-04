<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentPreviewService
{
    public function show(User $owner, string $guid): array
    {
        abort_unless(config('operations.payment_preview_enabled'), 404);
        $booking = DB::table('bookings')->where('guid', $guid)->where('user_guid', $owner->guid)->first();
        abort_unless($booking, 404);

        return ['booking_guid' => $guid, 'availability' => $booking->status === 'awaiting_payment' ? 'preview_only' : 'unavailable',
            'reason' => $booking->status === 'awaiting_payment' ? null : 'booking_not_awaiting_payment',
            'provider' => 'doku', 'integration_status' => 'not_configured', 'can_pay' => false,
            'payment_status' => 'unverified', 'presentation_styles' => ['qris', 'virtual_account', 'ewallet']];
    }
}
