<?php

declare(strict_types=1);

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
        Schema::create('pos_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('location_id')->nullable();
            $table->uuid('terminal_id');
            $table->uuid('shift_id');
            $table->uuid('table_id')->nullable();
            $table->string('order_number', 50);
            $table->string('status', 20)->default('open');
            $table->uuid('cashier_id');
            $table->string('cashier_name', 255);
            $table->string('customer_name', 255)->nullable();
            $table->string('customer_identifier', 255)->nullable();
            $table->uuid('partner_id')->nullable();
            $table->decimal('subtotal', 15, 4)->default(0);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('discount_amount', 15, 4)->default(0);
            $table->decimal('total', 15, 4)->default(0);
            $table->string('currency', 3)->default('TND');
            $table->string('consumption_mode', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('receipt_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('terminal_id')->references('id')->on('pos_terminals');
            $table->foreign('shift_id')->references('id')->on('pos_shifts');
            $table->foreign('cashier_id')->references('id')->on('users');
            $table->foreign('partner_id')->references('id')->on('partners');
            $table->foreign('receipt_id')->references('id')->on('pos_receipts');

            $table->index(['terminal_id', 'status']);
            $table->index(['shift_id']);
            $table->index(['partner_id']);
            $table->index(['opened_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_orders');
    }
};
