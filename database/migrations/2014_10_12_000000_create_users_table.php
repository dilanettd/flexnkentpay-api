<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->index();
            $table->string("facebookId")->unique()->nullable()->index();
            $table->string("googleId")->unique()->nullable()->index();
            $table->string('name');
            $table->string('email')->unique()->index();
            $table->string("phone")->nullable()->index();
            $table->string('profile_url')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('email_verification_token')->nullable();
            $table->timestamp('email_verification_token_expires_at')->nullable();
            $table->string('phone_verification_token')->nullable();
            $table->timestamp('phone_verification_token_expires_at')->nullable();
            $table->string('password');
            $table->string('role')->default('customer');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
