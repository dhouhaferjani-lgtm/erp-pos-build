<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->uuid('purchase_order_id');
            $table->string('receipt_number', 30);
            $table->string('status', 20);
            $table->timestampTz('received_at');
            $table->uuid('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'receipt_number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->foreignUuid('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->uuid('po_line_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->decimal('received_qty', 15, 4);
            $table->decimal('free_qty', 15, 4)->default('0.0000');
            $table->decimal('received_unit_price', 15, 3)->nullable();
            $table->decimal('landed_unit_cost', 19, 6);
            $table->decimal('accrual_unit_cost', 15, 6);
            $table->decimal('effective_unit_cost', 19, 6);
            $table->uuid('movement_id')->nullable();
            $table->uuid('free_movement_id')->nullable();
            $table->decimal('quantity_invoiced', 15, 4)->default('0.0000');
            $table->uuid('price_override_by')->nullable();
            $table->timestampTz('price_override_at')->nullable();
            $table->decimal('price_override_old_basis', 15, 6)->nullable();
            $table->string('price_override_reason', 255)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'po_line_id']);
            $table->index(['tenant_id', 'goods_receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
