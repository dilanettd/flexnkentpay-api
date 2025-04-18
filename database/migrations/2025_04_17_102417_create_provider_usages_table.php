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
        Schema::create('provider_usages', function (Blueprint $table) {
            $table->id();
            $table->string('provider_name')->unique();
            $table->decimal('total_deposit_amount', 15, 2)->default(0);
            $table->decimal('total_withdrawal_amount', 15, 2)->default(0);
            $table->integer('total_transactions')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_usages');
    }
};