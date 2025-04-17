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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('slug')->unique();
            $table->string('category');
            $table->string('subcategory')->nullable();
            $table->longText('description')->nullable();
            $table->string('currency', 3);
            $table->decimal('price', 15, 2);
            $table->decimal('rating', 3, 2)->default(0);
            $table->integer('visit')->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->integer('installment_count')->default(1);
            $table->decimal('min_installment_price', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
