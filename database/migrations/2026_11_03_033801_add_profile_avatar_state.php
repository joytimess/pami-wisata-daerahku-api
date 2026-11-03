<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_source', 16)->default('default');
            $table->boolean('avatar_manual_choice')->default(false);
            $table->string('avatar_custom_path')->nullable();
            $table->string('google_avatar_snapshot_path')->nullable();
            $table->unsignedBigInteger('avatar_version')->default(0);
        });
        Schema::create('avatar_file_cleanups', function (Blueprint $table): void {
            $table->id();
            $table->string('path')->unique();
            $table->timestamp('eligible_at')->index();
            $table->unsignedInteger('attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_file_cleanups');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_source', 'avatar_manual_choice', 'avatar_custom_path', 'google_avatar_snapshot_path', 'avatar_version']);
        });
    }
};
