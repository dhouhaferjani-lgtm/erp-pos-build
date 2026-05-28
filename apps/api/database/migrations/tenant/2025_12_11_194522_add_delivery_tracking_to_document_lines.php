<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add delivery tracking columns to document_lines for partial delivery support.
 *
 * Tunisia compliance requires:
 * - Multiple delivery notes from single sales order
 * - Line-level tracking of delivered quantities
 * - Link delivery note lines to source order lines
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            // Track how much of this line's quantity has been delivered
            // Only applicable to Sales Order lines
            $table->decimal('quantity_delivered', 15, 4)->default(0)->after('quantity');

            // For delivery note lines, reference the source order line
            // This enables tracking which DN line came from which SO line
            $table->foreignUuid('source_line_id')
                ->nullable()
                ->after('notes')
                ->constrained('document_lines')
                ->nullOnDelete();

            // Index for efficient delivery status queries
            $table->index(['document_id', 'quantity_delivered'], 'idx_delivery_tracking');
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropForeign(['source_line_id']);
            $table->dropIndex('idx_delivery_tracking');
            $table->dropColumn(['quantity_delivered', 'source_line_id']);
        });
    }
};
