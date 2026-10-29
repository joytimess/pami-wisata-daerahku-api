<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->string('provider');
            $table->string('provider_subject');
            $table->unique(['provider', 'provider_subject']);
            $table->timestamps();
        });
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->uuid('guid')->primary();
            $table->foreignUuid('user_guid')->constrained('users', 'guid');
            $table->string('token_hash', 64)->unique();
            $table->uuid('session_guid')->index();
            $table->string('device_name')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('replaced_by_guid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('external_identities');
    }
};
