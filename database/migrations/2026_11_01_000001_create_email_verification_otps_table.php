<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_otps', function (Blueprint $table) {
            $table->foreignUuid('user_guid')->primary()->constrained('users', 'guid')->cascadeOnDelete();
            $table->string('email');
            $table->string('purpose')->default('verify_email');
            $table->string('digest', 64);
            $table->uuid('issuance_id');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_otps');
    }
};
