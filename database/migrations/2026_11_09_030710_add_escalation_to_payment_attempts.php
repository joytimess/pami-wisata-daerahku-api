<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive operational marker: an attempt still unresolved after its instruction window is escalated once to operators.
     * It changes no payment state, frees no quota and never creates a new attempt.
     */
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->timestamp('escalated_at')->nullable();
            $table->string('escalation_reason', 64)->nullable();
            $table->index(['provider', 'environment', 'status', 'escalated_at'], 'payment_attempts_escalation_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex('payment_attempts_escalation_index');
            $table->dropColumn(['escalated_at', 'escalation_reason']);
        });
    }
};
