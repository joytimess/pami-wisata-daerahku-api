<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->unique('booking_guid');
            $table->string('invoice_number')->unique();
            $table->string('status');
            $table->string('pdf_path')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->constrained('bookings', 'guid');
            $table->unique('booking_guid');
            $table->char('token_hash', 64)->unique();
            $table->string('status');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('pdf_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('invoices');
    }
};
