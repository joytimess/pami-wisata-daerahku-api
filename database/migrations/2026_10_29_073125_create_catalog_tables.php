<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_categories', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('destinations', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('destination_category_guid')->constrained('destination_categories', 'guid');
            $table->string('name');
            $table->string('location');
            $table->text('description');
            $table->unsignedBigInteger('ticket_price');
            $table->char('currency', 3)->default('IDR');
            $table->text('opening_hours');
            $table->string('timezone');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });
        Schema::create('destination_quotas', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('destination_guid')->constrained('destinations', 'guid');
            $table->date('visit_date');
            $table->unsignedInteger('capacity');
            $table->timestamp('booking_opens_at');
            $table->timestamp('booking_closes_at');
            $table->timestamps();
            $table->unique(['destination_guid', 'visit_date']);
        });
        Schema::create('favorites', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->foreignUuid('destination_guid')->constrained('destinations', 'guid');
            $table->timestamps();
            $table->unique(['user_guid', 'destination_guid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('destination_quotas');
        Schema::dropIfExists('destinations');
        Schema::dropIfExists('destination_categories');
    }
};
