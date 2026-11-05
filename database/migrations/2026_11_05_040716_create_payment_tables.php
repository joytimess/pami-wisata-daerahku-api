<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->unique()->constrained('bookings', 'guid');
            $table->string('provider', 32);
            $table->string('environment', 16);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount');
            $table->enum('status', ['requires_payment', 'processing', 'paid', 'expired', 'needs_review', 'cancelled'])->default('requires_payment');
            $table->string('merchant_reference', 64);
            $table->timestamp('expires_at');
            $table->timestamp('verified_paid_at')->nullable();
            $table->uuid('applied_attempt_guid')->nullable()->unique();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'merchant_reference'], 'payment_intents_merchant_unique');
            $table->index(['provider', 'environment', 'verified_paid_at'], 'payment_intents_paid_index');
        });
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('payment_intent_guid')->constrained('payment_intents', 'guid');
            $table->string('provider', 32);
            $table->string('environment', 16);
            $table->unsignedSmallInteger('attempt_number');
            $table->string('method', 32);
            $table->string('channel', 64)->nullable();
            $table->string('merchant_reference', 64);
            $table->string('provider_request_id', 128);
            $table->string('provider_reference', 128)->nullable();
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->enum('status', ['initiating', 'pending', 'failed', 'expired', 'unknown', 'paid', 'reversed'])->default('initiating');
            $table->string('presentation_type', 32)->default('unavailable');
            $table->text('instruction_ciphertext')->nullable();
            $table->timestamp('instruction_expires_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('verified_paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->unique(['payment_intent_guid', 'attempt_number'], 'payment_attempts_number_unique');
            $table->unique(['payment_intent_guid', 'guid'], 'payment_attempts_intent_guid_unique');
            $table->unique(['provider', 'environment', 'merchant_reference'], 'payment_attempts_merchant_unique');
            $table->unique(['provider', 'environment', 'provider_request_id'], 'payment_attempts_request_unique');
            $table->unique(['provider', 'environment', 'provider_reference'], 'payment_attempts_reference_unique');
            $table->index(['provider', 'environment', 'verified_paid_at'], 'payment_attempts_paid_index');
        });
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->foreign(['guid', 'applied_attempt_guid'])->references(['payment_intent_guid', 'guid'])->on('payment_attempts');
        });
        Schema::create('payment_events', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('payment_intent_guid')->nullable()->constrained('payment_intents', 'guid');
            $table->foreignUuid('payment_attempt_guid')->nullable()->constrained('payment_attempts', 'guid');
            $table->string('provider', 32);
            $table->string('environment', 16);
            $table->string('provider_event_key', 128);
            $table->char('payload_hash', 64);
            $table->enum('verification', ['unverified', 'verified', 'rejected'])->default('unverified');
            $table->enum('event_type', ['status', 'capture', 'refund', 'reversal'])->default('status');
            $table->string('disposition', 32)->default('recorded');
            $table->unsignedBigInteger('amount')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('provider_occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->json('metadata')->nullable();
            $table->unique(['provider', 'environment', 'provider_event_key'], 'payment_events_provider_unique');
            $table->index(['payment_attempt_guid', 'verification', 'event_type'], 'payment_events_evidence_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropForeign(['guid', 'applied_attempt_guid']);
        });
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_intents');
    }
};
