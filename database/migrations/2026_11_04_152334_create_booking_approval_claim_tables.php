<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_approval_claims', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->unique()->constrained('bookings', 'guid');
            $table->foreignUuid('claimed_by_user_guid')->nullable()->constrained('users', 'guid');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['claimed_by_user_guid', 'expires_at'], 'approval_claim_owner_expiry');
        });
        Schema::create('booking_approval_claim_histories', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->foreignUuid('actor_guid')->nullable()->constrained('users', 'guid');
            $table->foreignUuid('from_user_guid')->nullable()->constrained('users', 'guid');
            $table->foreignUuid('to_user_guid')->nullable()->constrained('users', 'guid');
            $table->string('event', 24);
            $table->string('reason')->nullable();
            $table->timestamp('created_at');
            $table->index(['booking_guid', 'created_at'], 'approval_claim_history_booking_time');
        });
        Schema::table('booking_status_histories', function (Blueprint $table) {
            $table->index(['old_status', 'created_at', 'booking_guid'], 'booking_decision_period');
            $table->index(['actor_guid', 'created_at'], 'booking_actor_period');
        });
    }

    public function down(): void
    {
        Schema::table('booking_status_histories', function (Blueprint $table) {
            $table->dropIndex('booking_decision_period');
            $table->dropIndex('booking_actor_period');
        });
        Schema::dropIfExists('booking_approval_claim_histories');
        Schema::dropIfExists('booking_approval_claims');
    }
};
