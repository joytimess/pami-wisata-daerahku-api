<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only (Phase 4.1). No existing row, column or constraint is changed.
     *
     * `payment_recovery_actions` is the append-only audit trail of operator decisions about stuck payments, attempts and refunds: who,
     * what evidence, which server-verified facts and what was refused. Applied rows carry an idempotency key (unique per actor);
     * refused rows do not, so a refusal never blocks a later retry once the evidence exists.
     *
     * `payment_intents.review_reason` records WHY an intent entered review, so recovery can tell a resolvable case from one that
     * only engineering may touch. `dispatch_claim_token` makes a refund worker's lease ownable: only the holder can release it.
     * `recovery_exhausted_at` marks an open attempt whose bounded scheduled recovery ended; it stays open and holds its quota.
     */
    public function up(): void
    {
        Schema::create('payment_recovery_actions', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->string('subject_type', 24);
            $table->uuid('subject_guid');
            $table->foreignUuid('actor_guid')->constrained('users', 'guid');
            $table->string('action', 40);
            $table->string('outcome', 16);
            $table->string('refusal_reason', 64)->nullable();
            $table->string('evidence_type', 48)->nullable();
            $table->string('evidence_reference', 128)->nullable();
            $table->string('evidence_note', 500)->nullable();
            $table->json('verified_facts')->nullable();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->unique(['actor_guid', 'idempotency_key'], 'payment_recovery_actor_key_unique');
            $table->index(['booking_guid', 'created_at'], 'payment_recovery_booking_created_index');
            $table->index(['subject_guid', 'created_at'], 'payment_recovery_subject_created_index');
        });

        Schema::table('payment_intents', function (Blueprint $table) {
            $table->string('review_reason', 64)->nullable();
            $table->timestamp('review_flagged_at')->nullable();
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->timestamp('recovery_exhausted_at')->nullable();
        });

        Schema::table('booking_refund_requests', function (Blueprint $table) {
            $table->string('dispatch_claim_token', 36)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_refund_requests', function (Blueprint $table) {
            $table->dropColumn('dispatch_claim_token');
        });
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn('recovery_exhausted_at');
        });
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropColumn(['review_reason', 'review_flagged_at']);
        });
        // The audit trail is deliberately kept: rolling back must never destroy the history of an operator's money decisions.
    }
};
