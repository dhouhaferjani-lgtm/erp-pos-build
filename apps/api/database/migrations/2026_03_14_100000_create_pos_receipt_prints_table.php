<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipt_prints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('receipt_id')->references('id')->on('pos_receipts');
            $table->foreignUuid('terminal_id')->references('id')->on('pos_terminals');
            $table->foreignUuid('user_id')->references('id')->on('users');
            $table->string('print_type');
            $table->integer('copy_number');
            $table->timestampTz('printed_at');
            $table->string('print_method');
            $table->timestampTz('created_at');

            $table->index('receipt_id');
            $table->index('terminal_id');
            $table->index('user_id');
            $table->index(['receipt_id', 'copy_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_prints');
    }
};
