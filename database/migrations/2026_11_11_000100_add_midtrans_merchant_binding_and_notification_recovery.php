<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only: no historical row, provider or migration is touched.
     *
     * - payment_attempts.merchant_id: merchant identity snapshotted at reservation, so a later credential change can never
     *   verify (or re-bind) an order that was created under another merchant. Null for DOKU, DEMO and legacy rows.
     * - payment_events.claimed_until / process_attempts: durable lease and retry counter for notification receipts, so parallel
     *   workers never process one receipt twice and a failed receipt is retried with backoff.
     * - payment_events_inbox_index: the replay scan over unprocessed receipts and unapplied verified events.
     */
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->string('merchant_id', 64)->nullable();
        });
        Schema::table('payment_events', function (Blueprint $table) {
            $table->timestamp('claimed_until')->nullable();
            $table->unsignedSmallInteger('process_attempts')->default(0);
            $table->index(['provider', 'environment', 'verification', 'disposition', 'received_at'], 'payment_events_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->dropIndex('payment_events_inbox_index');
            $table->dropColumn(['claimed_until', 'process_attempts']);
        });
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn('merchant_id');
        });
    }
};
