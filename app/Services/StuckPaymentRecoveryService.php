<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Midtrans bookings that an older release parked in payment_verification / payment_review only because a clock ran out. Nothing here
 * decides money: an unpaid cycle is only closed by the same proven-evidence path the scheduler uses, open attempts only get their next
 * provider lookup brought forward, and anything with paid, unknown or exhausted evidence is reported for an operator and left alone.
 */
class StuckPaymentRecoveryService
{
    public const CLOSABLE = 'closable';

    public const LOOKUP = 'lookup';

    public const OPERATOR = 'operator';

    public function __construct(private PaymentSettlementService $settlement) {}

    /** @return array{closable: int, lookup: int, operator: int, closed: int, lookups_requested: int, operator_guids: list<string>} */
    public function run(bool $dryRun): array
    {
        $result = ['closable' => 0, 'lookup' => 0, 'operator' => 0, 'closed' => 0, 'lookups_requested' => 0, 'operator_guids' => []];
        DB::table('bookings')->whereIn('status', ['payment_verification', 'payment_review'])->orderBy('guid')->chunkById(100, function ($bookings) use (&$result, $dryRun) {
            foreach ($bookings as $booking) {
                $class = $this->classify($booking);
                if ($class === null) {
                    continue;
                }
                $result[$class]++;
                if ($class === self::OPERATOR) {
                    $result['operator_guids'][] = $booking->guid;
                } elseif (! $dryRun && $class === self::CLOSABLE) {
                    $result['closed'] += $this->settlement->expireIfTerminalUnpaid($booking->guid) ? 1 : 0;
                } elseif (! $dryRun && $class === self::LOOKUP) {
                    $result['lookups_requested'] += $this->requestLookup($booking->guid);
                }
            }
        }, 'guid');

        return $result;
    }

    /** null: not a Midtrans clock-parked booking (legacy DOKU/DEMO, or no deadline yet), so it is not this tool's business. */
    private function classify(object $booking): ?string
    {
        $intent = DB::table('payment_intents')->where('booking_guid', $booking->guid)->first();
        if (! $intent || $intent->provider !== 'midtrans' || $booking->payment_expires_at === null || now()->lt($booking->payment_expires_at)) {
            return null;
        }
        if ($intent->applied_attempt_guid !== null || ! in_array($intent->status, ['requires_payment', 'processing'], true)) {
            return self::OPERATOR;
        }
        $attempts = DB::table('payment_attempts')->where('payment_intent_guid', $intent->guid)->get(['status', 'recovery_exhausted_at']);
        if ($attempts->contains(fn ($a) => ! in_array($a->status, ['failed', 'expired', 'initiating', 'pending', 'unknown'], true))) {
            return self::OPERATOR; // paid, refunded, review or any state this tool must not interpret
        }
        $open = $attempts->filter(fn ($a) => in_array($a->status, ['initiating', 'pending', 'unknown'], true));
        if ($open->isEmpty()) {
            return self::CLOSABLE;
        }

        return $open->contains(fn ($a) => $a->recovery_exhausted_at !== null) ? self::OPERATOR : self::LOOKUP;
    }

    private function requestLookup(string $bookingGuid): int
    {
        return DB::table('payment_attempts')->whereIn('payment_intent_guid', DB::table('payment_intents')->where('booking_guid', $bookingGuid)->select('guid'))
            ->whereIn('status', ['initiating', 'pending', 'unknown'])->whereNull('recovery_exhausted_at')
            ->where(fn ($due) => $due->whereNull('next_reconcile_at')->orWhere('next_reconcile_at', '>', now()->addSeconds(60)))
            ->update(['next_reconcile_at' => now()]) > 0 ? 1 : 0;
    }
}
