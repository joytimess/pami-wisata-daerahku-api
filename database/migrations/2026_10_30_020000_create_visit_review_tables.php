<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->unique()->constrained('bookings', 'guid');
            $table->foreignUuid('actor_guid')->constrained('users', 'guid');
            $table->timestamp('checked_in_at');
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('booking_guid')->unique()->constrained('bookings', 'guid');
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->foreignUuid('destination_guid')->constrained('destinations', 'guid');
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->timestamp('created_at');
            $table->index(['destination_guid', 'created_at']);
            $table->index(['user_guid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('check_ins');
    }
};
