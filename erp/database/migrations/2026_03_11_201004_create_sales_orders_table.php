<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('external_id')->unique(); // stable ID for sync
            $table->integer('customer_id');

            $table->string('order_number')->unique();
            $table->string('status')->default('new');
            $table->string('currency')->default('EUR');
            $table->decimal('total', 12, 2)->default(0);

            $table->date('issued_at')->nullable();

            $table->date('received_at')->nullable();


            // sync tracking TODO: figure out if we need these in ERP or just in CRM
            // $table->timestamp('synced_at')->nullable();
            // $table->string('erp_reference')->nullable(); // ERP order id/number etc.
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
