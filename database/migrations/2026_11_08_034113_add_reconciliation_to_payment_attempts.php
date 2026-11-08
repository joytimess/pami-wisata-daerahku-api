<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->timestamp('next_reconcile_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->unsignedInteger('reconcile_count')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->index(['provider', 'environment', 'status', 'next_reconcile_at'], 'payment_attempts_reconcile_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex('payment_attempts_reconcile_index');
            $table->dropColumn(['next_reconcile_at', 'last_reconciled_at', 'reconcile_count', 'last_error_code']);
        });
    }
};
