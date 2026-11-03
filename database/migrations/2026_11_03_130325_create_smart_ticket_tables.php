<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('destination_timezone_snapshot')->nullable();
        });
        Schema::create('booking_tickets', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->unsignedInteger('sequence');
            $table->char('qr_token_hash', 64)->nullable()->unique();
            $table->text('qr_token_encrypted')->nullable();
            $table->timestamp('qr_issued_at')->nullable();
            $table->timestamp('qr_revoked_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignUuid('checked_in_by_user_guid')->nullable()->constrained('users', 'guid');
            $table->timestamps();
            $table->unique(['booking_guid', 'sequence']);
        });
        Schema::create('ticket_check_ins', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('ticket_guid')->unique()->constrained('booking_tickets', 'guid');
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->foreignUuid('actor_guid')->constrained('users', 'guid');
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamp('checked_in_at');
            $table->timestamp('created_at');
            $table->unique(['actor_guid', 'idempotency_key']);
        });
        Schema::create('destination_admin_assignments', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('destination_guid')->constrained('destinations', 'guid');
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->foreignUuid('assigned_by_user_guid')->constrained('users', 'guid');
            $table->timestamp('created_at');
            $table->unique(['destination_guid', 'user_guid']);
        });
        Schema::create('destination_admin_assignment_histories', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('destination_guid')->constrained('destinations', 'guid');
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->foreignUuid('actor_guid')->constrained('users', 'guid');
            $table->string('action', 16);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_admin_assignment_histories');
        Schema::dropIfExists('destination_admin_assignments');
        Schema::dropIfExists('ticket_check_ins');
        Schema::dropIfExists('booking_tickets');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('destination_timezone_snapshot');
        });
    }
};
