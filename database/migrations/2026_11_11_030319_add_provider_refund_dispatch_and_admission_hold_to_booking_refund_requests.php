<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only: provider-API refund dispatch, its recovery and the temporary admission hold.
     *
     * `refund_key` is persisted BEFORE any provider call and is never regenerated, so a retry, a replay or a worker crash can only
     * ever resend the same provider idempotency key. `dispatch_started_at` marks that the provider may already have received the
     * request and is never cleared. `admission_hold` is 1 while an automatic refund is claimed or unresolved and NULL otherwise;
     * check-in reads it under the booking lock. No existing row or constraint is changed.
     */
    public function up(): void
    {
        Schema::table('booking_refund_requests', function (Blueprint $table) {
            $table->string('refund_route', 16)->nullable()->after('refund_channel');
            $table->string('refund_key', 64)->nullable()->after('refund_route');
            $table->string('provider_refund_reference', 128)->nullable()->after('refund_key');
            $table->unsignedTinyInteger('admission_hold')->nullable()->after('provider_refund_reference');
            $table->timestamp('dispatch_claimed_until')->nullable()->after('admission_hold');
            $table->timestamp('dispatch_started_at')->nullable()->after('dispatch_claimed_until');
            $table->unsignedSmallInteger('dispatch_attempts')->default(0)->after('dispatch_started_at');
            $table->timestamp('next_reconcile_at')->nullable()->after('dispatch_attempts');
            $table->timestamp('last_reconciled_at')->nullable()->after('next_reconcile_at');
            $table->unsignedSmallInteger('reconcile_count')->default(0)->after('last_reconciled_at');
            $table->string('last_error_code', 64)->nullable()->after('reconcile_count');
            $table->timestamp('escalated_at')->nullable()->after('last_error_code');
            $table->unique('refund_key', 'refund_requests_refund_key_unique');
            $table->index(['booking_guid', 'admission_hold'], 'refund_requests_admission_hold_index');
            $table->index(['refund_route', 'status', 'next_reconcile_at'], 'refund_requests_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('booking_refund_requests', function (Blueprint $table) {
            $table->dropIndex('refund_requests_recovery_index');
            $table->dropIndex('refund_requests_admission_hold_index');
            $table->dropUnique('refund_requests_refund_key_unique');
            $table->dropColumn(['refund_route', 'refund_key', 'provider_refund_reference', 'admission_hold', 'dispatch_claimed_until',
                'dispatch_started_at', 'dispatch_attempts', 'next_reconcile_at', 'last_reconciled_at', 'reconcile_count', 'last_error_code', 'escalated_at']);
        });
    }
};
