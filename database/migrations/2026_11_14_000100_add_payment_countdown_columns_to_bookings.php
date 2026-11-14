<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // The payment countdown starts when the customer picks a method, not at approval.
            $table->timestamp('payment_started_at')->nullable()->after('approval_expires_at');
            // Latest moment a method can still be chosen after approval (24h from approval, never past the visit start).
            $table->timestamp('payment_selection_expires_at')->nullable()->after('payment_started_at');
            $table->unsignedSmallInteger('payment_cycle')->default(0)->after('payment_selection_expires_at');
            $table->index(['status', 'payment_selection_expires_at'], 'bookings_status_selection_expiry_index');
        });
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_cycle')->default(1)->after('attempt_number');
        });

        // Bookings that already have a payment attempt keep the deadline that is bound to the provider.
        DB::table('bookings')->whereIn('status', ['awaiting_payment', 'payment_verification', 'payment_review', 'confirmed'])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('payment_intents')->whereColumn('payment_intents.booking_guid', 'bookings.guid'))
            ->orderBy('guid')->each(function ($booking) {
                $started = DB::table('payment_attempts')->join('payment_intents', 'payment_intents.guid', '=', 'payment_attempts.payment_intent_guid')
                    ->where('payment_intents.booking_guid', $booking->guid)->min('payment_attempts.started_at');
                DB::table('bookings')->where('guid', $booking->guid)->update(['payment_started_at' => $started]);
            });

        // Awaiting payment without any attempt: they get the full countdown when they choose a method.
        $now = now();
        DB::table('bookings')->where('status', 'awaiting_payment')->whereNull('payment_started_at')
            ->orderBy('guid')->each(function ($booking) use ($now) {
                $approvedAt = DB::table('booking_status_histories')->where('booking_guid', $booking->guid)->where('new_status', 'awaiting_payment')->max('created_at');
                $visit = DB::table('destination_quotas')->where('guid', $booking->destination_quota_guid)->first(['visit_date', 'destination_guid']);
                $timezone = $booking->destination_timezone_snapshot
                    ?? DB::table('destinations')->where('guid', $visit->destination_guid)->value('timezone') ?? 'UTC';
                $visitStart = \Carbon\CarbonImmutable::parse(substr((string) $visit->visit_date, 0, 10), $timezone)->startOfDay()->utc();
                $selection = \Carbon\CarbonImmutable::parse($approvedAt ?? $now, 'UTC')->addHours(24)->min($visitStart);
                DB::table('bookings')->where('guid', $booking->guid)->update([
                    'payment_expires_at' => null, 'notification_grace_expires_at' => null,
                    'payment_selection_expires_at' => $selection->max($now->copy()->addMinutes(15)->toImmutable()->min($visitStart)),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn('payment_cycle'));
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_status_selection_expiry_index');
            $table->dropColumn(['payment_started_at', 'payment_selection_expires_at', 'payment_cycle']);
        });
    }
};
