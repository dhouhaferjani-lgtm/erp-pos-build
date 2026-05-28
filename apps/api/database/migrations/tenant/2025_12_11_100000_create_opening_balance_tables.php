<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Main opening balance batches table
        Schema::create('opening_balance_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('type', 50);                    // ACCOUNTING, INVENTORY, AR_OPEN_ITEMS, AP_OPEN_ITEMS
            $table->string('name', 255);                   // "Initial Opening - 2025-01-01"
            $table->date('cutover_date');
            $table->string('status', 20)->default('DRAFT'); // DRAFT, VALIDATED, LOCKED
            $table->string('source_system', 100)->nullable(); // "Sage", "Excel", etc.
            $table->jsonb('import_file_reference')->nullable();
            $table->string('hash', 64)->nullable();        // SHA-256 hash
            $table->string('previous_hash', 64)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->uuid('validated_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('locked_by')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('validated_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('locked_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');

            // Indexes
            $table->index(['tenant_id', 'company_id', 'type']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'cutover_date']);
        });

        // Staging table for imported rows (for validation before posting)
        Schema::create('opening_balance_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id');
            $table->string('row_type', 20);               // GL, INVENTORY, AR, AP
            $table->unsignedInteger('row_number');        // Original row number from import file
            $table->jsonb('raw_data');                    // Original CSV row as JSON
            $table->string('status', 20)->default('PENDING'); // PENDING, VALID, INVALID, SKIPPED, POSTED
            $table->jsonb('validation_errors')->nullable();
            $table->jsonb('mapped_data')->nullable();     // Resolved IDs and values
            $table->uuid('mapped_entity_id')->nullable(); // ID in final table once posted
            $table->timestamps();

            $table->foreign('batch_id')
                ->references('id')
                ->on('opening_balance_batches')
                ->onDelete('cascade');

            // Indexes
            $table->index(['batch_id', 'status']);
            $table->index(['batch_id', 'row_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_import_rows');
        Schema::dropIfExists('opening_balance_batches');
    }
};
