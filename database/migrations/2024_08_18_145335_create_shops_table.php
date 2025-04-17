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
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('logo_url')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('cover_photo_url')->nullable();
            $table->string('website_url')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->integer('visit_count')->default(0);
            $table->longText('description')->nullable();
            $table->string('coordinate')->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
