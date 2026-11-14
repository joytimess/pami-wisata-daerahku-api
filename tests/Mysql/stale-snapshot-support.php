<?php

use Illuminate\Support\Facades\DB;

if (! function_exists('snsProbe')) {
    /**
     * Plain (non-locking, consistent-read) view of everything the stale-snapshot races decide on, for one booking.
     *
     * Executed inside an already open REPEATABLE READ transaction it reports that transaction's frozen snapshot; executed on an
     * idle connection it reports the committed truth. The two are compared to prove that a worker really decided from stale data.
     *
     * @return array<string, mixed>
     */
    function snsProbe(string $bookingGuid): array
    {
        $histories = DB::table('booking_status_histories')->where('booking_guid', $bookingGuid)
            ->selectRaw('new_status, COUNT(*) AS total')->groupBy('new_status')->pluck('total', 'new_status');
        $intent = DB::table('payment_intents')->where('booking_guid', $bookingGuid)->first(['guid', 'status']);

        return [
            'booking_status' => DB::table('bookings')->where('guid', $bookingGuid)->value('status'),
            'check_in_rows' => DB::table('ticket_check_ins')->where('booking_guid', $bookingGuid)->count(),
            'checked_in_tickets' => DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->whereNotNull('checked_in_at')->count(),
            'revoked_tickets' => DB::table('booking_tickets')->where('booking_guid', $bookingGuid)->whereNotNull('qr_revoked_at')->count(),
            'admission_holds' => DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->where('admission_hold', 1)->count(),
            'settled_refunds' => DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->whereIn('status', ['refunded', 'manual_refunded'])->count(),
            'refund_statuses' => DB::table('booking_refund_requests')->where('booking_guid', $bookingGuid)->orderBy('created_at')->orderBy('guid')->pluck('status')->all(),
            'intent_status' => $intent?->status,
            'attempt_statuses' => $intent
                ? DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->orderBy('attempt_number')->pluck('status')->all()
                : [],
            'history_payment_verification' => (int) ($histories['payment_verification'] ?? 0),
            'history_confirmed' => (int) ($histories['confirmed'] ?? 0),
            'history_expired' => (int) ($histories['expired'] ?? 0),
            'history_cycle_reset' => DB::table('booking_status_histories')->where('booking_guid', $bookingGuid)->where('reason', 'payment_cycle_reset')->count(),
        ];
    }
}
