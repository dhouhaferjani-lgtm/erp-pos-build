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
        Schema::create('credit_note_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('credit_note_id')
                ->constrained('documents')
                ->onDelete('cascade');
            $table->foreignUuid('invoice_id')
                ->constrained('documents')
                ->onDelete('restrict');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            // Indexes for performance
            $table->index('credit_note_id');
            $table->index('invoice_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_note_allocations');
    }
};
