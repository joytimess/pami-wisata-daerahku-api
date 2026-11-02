<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->string('name');
            $table->string('name_key')->unique();
            $table->string('slug')->unique();
            $table->string('icon_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });
        Schema::create('destination_facilities', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('destination_guid')->constrained('destinations', 'guid')->restrictOnDelete();
            $table->foreignUuid('facility_guid')->constrained('facilities', 'guid')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['destination_guid', 'facility_guid']);
            $table->index('facility_guid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_facilities');
        Schema::dropIfExists('facilities');
    }
};
