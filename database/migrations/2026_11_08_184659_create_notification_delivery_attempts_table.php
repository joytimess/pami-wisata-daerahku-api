<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit of each delivery try. A row left in `submitting` after a stale claim is the evidence of the
     * ambiguous window where the transport may have accepted the message before the outcome was stored.
     */
    public function up(): void
    {
        Schema::create('notification_delivery_attempts', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('delivery_guid')->constrained('notification_deliveries', 'guid');
            $table->unsignedInteger('attempt_number');
            $table->string('outcome', 24);
            $table->string('error_code', 64)->nullable();
            $table->string('message_reference', 255)->nullable();
            $table->timestamp('claimed_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['delivery_guid', 'attempt_number'], 'delivery_attempts_delivery_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_delivery_attempts');
    }
};
