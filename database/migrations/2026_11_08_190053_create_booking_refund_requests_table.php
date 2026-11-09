<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Full-refund workflow only. `active` is 1 while a request is non-terminal and NULL afterwards, so the unique key allows one
     * open request per booking and any number of closed ones. Nothing here changes payment_* rows: a refund is only ever
     * reflected as `refunded` after a verified provider event is applied by the payment engine, or as `manual_refunded` after an
     * admin verified an out-of-band transfer (a distinct status and channel; never presented as a provider refund).
     */
    public function up(): void
    {
        Schema::create('booking_refund_requests', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->foreignUuid('payment_intent_guid')->constrained('payment_intents', 'guid');
            $table->foreignUuid('payment_attempt_guid')->constrained('payment_attempts', 'guid');
            $table->foreignUuid('requested_by_user_guid')->constrained('users', 'guid');
            $table->string('idempotency_key', 128);
            $table->string('status', 32);
            $table->unsignedTinyInteger('active')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('reason', 500);
            $table->timestamp('requested_at');
            $table->foreignUuid('decided_by_user_guid')->nullable()->constrained('users', 'guid');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('review_reason', 64)->nullable();
            $table->string('provider_correlation', 128)->nullable();
            $table->timestamp('refunded_at')->nullable();
            // Manual settlement evidence (admin-verified transfer outside DOKU). `refund_channel` is 'manual' only for status
            // manual_refunded; a provider refund keeps it NULL/'provider' and is proven by provider_correlation instead.
            $table->string('refund_channel', 16)->nullable();
            $table->string('settlement_method', 32)->nullable();
            $table->string('settlement_reference', 128)->nullable();
            $table->unsignedBigInteger('settlement_amount')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignUuid('settled_by_user_guid')->nullable()->constrained('users', 'guid');
            $table->string('settlement_note', 500)->nullable();
            $table->timestamps();
            $table->unique('settlement_reference', 'refund_settlement_reference_unique');
            $table->unique(['booking_guid', 'idempotency_key'], 'refund_requests_booking_key_unique');
            $table->unique(['booking_guid', 'active'], 'refund_requests_one_active_unique');
            $table->index(['status', 'requested_at'], 'refund_requests_status_requested_index');
        });

        Schema::create('booking_refund_request_events', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('refund_request_guid')->constrained('booking_refund_requests', 'guid');
            $table->foreignUuid('actor_guid')->nullable()->constrained('users', 'guid');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['refund_request_guid', 'created_at'], 'refund_events_request_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_refund_request_events');
        Schema::dropIfExists('booking_refund_requests');
    }
};
