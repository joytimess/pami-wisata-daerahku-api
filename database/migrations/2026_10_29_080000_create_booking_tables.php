<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->foreignUuid('destination_quota_guid')->constrained('destination_quotas', 'guid');
            $table->string('booking_number')->unique();
            $table->unsignedInteger('quantity');
            $table->string('visitor_name');
            $table->string('visitor_email');
            $table->string('visitor_phone');
            $table->string('destination_name_snapshot');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total_amount');
            $table->char('currency', 3);
            $table->text('notes')->nullable();
            $table->string('status');
            $table->timestamp('approval_expires_at');
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('notification_grace_expires_at')->nullable();
            $table->timestamps();
            $table->index(['destination_quota_guid', 'status']);
            $table->index(['status', 'approval_expires_at']);
            $table->index(['status', 'payment_expires_at']);
        });
        Schema::create('booking_status_histories', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->foreignUuid('actor_guid')->nullable()->constrained('users', 'guid');
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('reason')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->string('operation', 64);
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->string('status');
            $table->uuid('response_reference')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_guid', 'operation', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('booking_status_histories');
        Schema::dropIfExists('bookings');
    }
};
