<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive nullable back-references from the fiscal Document model to
     * the Workshop WorkOrder aggregate.
     *
     * - `documents.work_order_id` IS an FK (preserves referential integrity
     *   with ON DELETE SET NULL so deleting a WO soft-breaks reporting but
     *   never cascades into a signed document).
     *
     * - `document_lines.work_order_line_id` is a **bare nullable UUID, NOT
     *   FK-constrained** per Spec §5.1. Avoids cross-module hard coupling
     *   — the Document module must continue to function without Workshop
     *   installed. Loose reference validated at application boundary.
     *
     * Both columns are defaulted NULL on existing rows. The fiscal hash
     * chain hashes only `document_number | posted_at | total | currency`,
     * so these additions are invariant-safe (exercised by
     * DocumentHashChainWorkOrderMigrationTest in Task 16).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignUuid('work_order_id')
                ->nullable()
                ->after('id')
                ->constrained('workshop_work_orders')
                ->nullOnDelete();
        });

        Schema::table('document_lines', function (Blueprint $table): void {
            // Bare nullable UUID — intentional: NOT FK-constrained per Spec §5.1.
            $table->uuid('work_order_line_id')->nullable()->after('id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX idx_documents_wo ON documents (work_order_id) '.
                'WHERE work_order_id IS NOT NULL'
            );
            DB::statement(
                'CREATE INDEX idx_document_lines_wol ON document_lines (work_order_line_id) '.
                'WHERE work_order_line_id IS NOT NULL'
            );
        } else {
            Schema::table('documents', function (Blueprint $table): void {
                $table->index('work_order_id', 'idx_documents_wo');
            });
            Schema::table('document_lines', function (Blueprint $table): void {
                $table->index('work_order_line_id', 'idx_document_lines_wol');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_documents_wo');
            DB::statement('DROP INDEX IF EXISTS idx_document_lines_wol');
        }

        Schema::table('document_lines', function (Blueprint $table): void {
            $table->dropColumn('work_order_line_id');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['work_order_id']);
            $table->dropColumn('work_order_id');
        });
    }
};
