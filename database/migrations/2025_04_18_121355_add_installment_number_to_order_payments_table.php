<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddInstallmentNumberToOrderPaymentsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->unsignedInteger('installment_number')->nullable()->after('amount_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropColumn('installment_number');
        });
    }
}
