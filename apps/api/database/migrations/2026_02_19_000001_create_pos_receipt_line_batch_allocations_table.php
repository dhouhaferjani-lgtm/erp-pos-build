<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipt_line_batch_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('receipt_id');
            $table->uuid('receipt_line_id');
            $table->unsignedBigInteger('batch_id');
            $table->decimal('quantity', 10, 3);
            $table->string('batch_number', 100);
            $table->date('expiry_date');
            $table->timestamps();

            $table->foreign('receipt_id')
                ->references('id')
                ->on('pos_receipts')
                ->onDelete('cascade');

            $table->foreign('batch_id')
                ->references('id')
                ->on('product_batches')
                ->onDelete('restrict');

            $table->index('receipt_id');
            $table->index('batch_id');
            $table->index('receipt_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_line_batch_allocations');
    }
};
