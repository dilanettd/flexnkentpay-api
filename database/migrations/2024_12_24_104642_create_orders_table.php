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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreignId('seller_id')->constrained('sellers')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->decimal('total_cost', 15, 2);
            $table->decimal('remaining_amount', 15, 2);
            $table->decimal('installment_amount', 15, 2);
            $table->integer('installment_count');
            $table->integer('remaining_installments');
            $table->string('payment_frequency');
            $table->integer('payment_duration_in_days')->default(0);
            $table->string('reminder_type');
            $table->decimal('penalty_percentage', 5, 2)->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
