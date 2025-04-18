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
        Schema::create('pawapay_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->index();
            $table->string('transaction_type');
            $table->timestamp('timestamp');
            $table->string('phone_number')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->nullable();
            $table->string('country', 3)->nullable();
            $table->string('correspondent')->nullable();
            $table->string('status');
            $table->string('description')->nullable();
            $table->timestamp('customer_timestamp')->nullable();
            $table->timestamp('created_timestamp')->nullable();
            $table->timestamp('received_timestamp')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->json('suspicious_activity_report')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pawapay_webhooks');
    }
};