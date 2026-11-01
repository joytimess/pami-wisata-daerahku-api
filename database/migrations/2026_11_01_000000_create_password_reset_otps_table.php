<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_rate_limits', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->unsignedBigInteger('window');
            $table->unsignedInteger('attempts');
            $table->unsignedBigInteger('expires_at')->index();
        });

        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->uuid('user_guid')->primary();
            $table->foreign('user_guid')->references('guid')->on('users')->cascadeOnDelete();
            $table->string('email');
            $table->uuid('issuance_id');
            $table->string('digest', 64);
            $table->timestamp('expires_at');
            $table->timestamp('sent_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('ticket_hash', 64)->nullable();
            $table->timestamp('ticket_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');
        Schema::dropIfExists('password_reset_rate_limits');
    }
};
