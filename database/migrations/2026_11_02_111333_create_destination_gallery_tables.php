<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', fn (Blueprint $table) => $table->unsignedInteger('gallery_version')->default(0));
        Schema::table('destinations', fn (Blueprint $table) => $table->uuid('last_gallery_operation_key')->nullable());
        Schema::create('destination_images', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('destination_guid')->constrained('destinations', 'guid');
            $table->unsignedInteger('sequence');
            $table->string('storage_disk');
            $table->string('thumbnail_path');
            $table->string('display_path');
            $table->string('fullscreen_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('mime_type');
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
            $table->unique(['destination_guid', 'sequence']);
        });
        Schema::create('destination_gallery_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('actor_guid')->constrained('users', 'guid');
            $table->uuid('operation_key');
            $table->string('payload_hash', 64);
            $table->json('response');
            $table->timestamp('created_at');
            $table->unique(['actor_guid', 'operation_key']);
        });
        Schema::create('destination_image_cleanups', function (Blueprint $table) {
            $table->id();
            $table->string('storage_disk');
            $table->string('path');
            $table->timestamp('eligible_at')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unique(['storage_disk', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_image_cleanups');
        Schema::dropIfExists('destination_gallery_operations');
        Schema::dropIfExists('destination_images');
        Schema::table('destinations', fn (Blueprint $table) => $table->dropColumn(['gallery_version', 'last_gallery_operation_key']));
    }
};
