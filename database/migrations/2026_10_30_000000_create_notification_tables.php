<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->foreignUuid('booking_guid')->nullable()->constrained('bookings', 'guid');
            $table->string('event_key');
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['user_guid', 'event_key']);
            $table->index(['user_guid', 'read_at']);
        });
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->uuid('session_guid');
            $table->string('token')->unique();
            $table->string('platform');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('notification_guid')->constrained('notifications', 'guid');
            $table->string('channel');
            $table->string('target_key');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
            $table->unique(
                ['notification_guid', 'channel', 'target_key'],
                'notif_delivery_target_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('notifications');
    }
};
