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
        Schema::table('document_lines', function (Blueprint $table): void {
            // Add service_id column to link document lines to services
            // Nullable because lines can reference products, services, or be manual
            $table->foreignUuid('service_id')
                ->nullable()
                ->after('product_id')
                ->constrained('services')
                ->nullOnDelete();

            // Index for service lookups
            $table->index('service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
