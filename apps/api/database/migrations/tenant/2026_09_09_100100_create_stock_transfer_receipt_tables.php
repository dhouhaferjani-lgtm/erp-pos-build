<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_transfer_receipts')) {
            Schema::create('stock_transfer_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                // tenant_id is a plain indexed uuid (NOT an FK): the `tenants` table lives in
                // the CENTRAL database, so under db-per-tenant a cross-DB FK is impossible.
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->uuid('transfer_id');
                $table->string('receipt_number', 80);
                $table->string('kind', 20);
                $table->string('disposition', 20)->nullable();
                $table->string('status', 20);
                $table->smallInteger('sequence');
                $table->boolean('is_blind');
                $table->boolean('has_discrepancy');
                $table->string('idempotency_key', 128);
                $table->char('payload_hash', 64);
                $table->uuid('received_by_user_id');
                $table->timestampTz('received_at');
                $table->text('notes')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('stock_transfer_receipt_lines')) {
            Schema::create('stock_transfer_receipt_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('receipt_id');
                $table->uuid('transfer_line_id');
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->uuid('product_id');
                $table->uuid('variant_id')->nullable();
                $table->boolean('is_lot_tracked');
                $table->decimal('quantity_received', 15, 4)->default(0);
                $table->decimal('quantity_damaged', 15, 4)->default(0);
                $table->decimal('quantity_written_off', 15, 4)->default(0);
                $table->decimal('quantity_returned', 15, 4)->default(0);
                $table->decimal('quantity_sent_snapshot', 15, 4);
                $table->string('discrepancy_reason', 32)->nullable();
                $table->text('discrepancy_note')->nullable();
                $table->uuid('in_movement_id')->nullable();
                $table->uuid('scrap_movement_id')->nullable();
                $table->uuid('return_movement_id')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('stock_transfer_receipt_line_lots')) {
            Schema::create('stock_transfer_receipt_line_lots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('receipt_line_id');
                $table->uuid('batch_allocation_id');
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->unsignedBigInteger('batch_id');
                $table->decimal('quantity_received', 15, 4)->default(0);
                $table->decimal('quantity_damaged', 15, 4)->default(0);
                $table->decimal('quantity_written_off', 15, 4)->default(0);
                $table->decimal('quantity_returned', 15, 4)->default(0);
                $table->uuid('in_movement_id')->nullable();
                $table->uuid('scrap_movement_id')->nullable();
                $table->uuid('return_movement_id')->nullable();
                $table->timestampsTz();
            });
        }

        $this->createIndexes();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->addForeignKeys();
        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipt_line_lots');
        Schema::dropIfExists('stock_transfer_receipt_lines');
        Schema::dropIfExists('stock_transfer_receipts');
    }

    private function createIndexes(): void
    {
        $statements = [
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipts_company_number_unique ON stock_transfer_receipts (tenant_id, company_id, receipt_number)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipts_idempotency_unique ON stock_transfer_receipts (tenant_id, company_id, idempotency_key)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipts_transfer_sequence_unique ON stock_transfer_receipts (transfer_id, sequence)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipts_company_transfer_idx ON stock_transfer_receipts (tenant_id, company_id, transfer_id)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipts_transfer_received_at_idx ON stock_transfer_receipts (transfer_id, received_at)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_receipt_line_unique ON stock_transfer_receipt_lines (receipt_id, transfer_line_id)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipt_lines_product_idx ON stock_transfer_receipt_lines (product_id)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_line_allocation_unique ON stock_transfer_receipt_line_lots (receipt_line_id, batch_allocation_id)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_batch_idx ON stock_transfer_receipt_line_lots (batch_id)',
        ];

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_in_movement_unique ON stock_transfer_receipt_lines (in_movement_id) WHERE in_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_scrap_movement_unique ON stock_transfer_receipt_lines (scrap_movement_id) WHERE scrap_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_return_movement_unique ON stock_transfer_receipt_lines (return_movement_id) WHERE return_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_in_movement_unique ON stock_transfer_receipt_line_lots (in_movement_id) WHERE in_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_scrap_movement_unique ON stock_transfer_receipt_line_lots (scrap_movement_id) WHERE scrap_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_return_movement_unique ON stock_transfer_receipt_line_lots (return_movement_id) WHERE return_movement_id IS NOT NULL';
        }

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    private function addForeignKeys(): void
    {
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_company_id_foreign',
            'FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_transfer_id_foreign',
            'FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_received_by_user_id_foreign',
            'FOREIGN KEY (received_by_user_id) REFERENCES users (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_receipt_id_foreign',
            'FOREIGN KEY (receipt_id) REFERENCES stock_transfer_receipts (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_transfer_line_id_foreign',
            'FOREIGN KEY (transfer_line_id) REFERENCES stock_transfer_lines (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_company_id_foreign',
            'FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_product_id_foreign',
            'FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_receipt_line_id_foreign',
            'FOREIGN KEY (receipt_line_id) REFERENCES stock_transfer_receipt_lines (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_batch_allocation_id_foreign',
            'FOREIGN KEY (batch_allocation_id) REFERENCES stock_transfer_line_batch_allocations (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_batch_id_foreign',
            'FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_company_id_foreign',
            'FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE'
        );
    }

    private function addChecks(): void
    {
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_close_has_disposition',
            "CHECK ((kind = 'close') = (disposition IS NOT NULL))"
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_quantities_non_negative',
            'CHECK (quantity_received >= 0 AND quantity_damaged >= 0 AND quantity_written_off >= 0 AND quantity_returned >= 0)'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_positive',
            'CHECK (quantity_received + quantity_damaged + quantity_written_off + quantity_returned > 0)'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_close_excludes_receipt',
            'CHECK (NOT (quantity_written_off > 0 OR quantity_returned > 0) OR (quantity_received = 0 AND quantity_damaged = 0))'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_lot_lines_have_no_parent_movement',
            'CHECK (is_lot_tracked = false OR (in_movement_id IS NULL AND scrap_movement_id IS NULL AND return_movement_id IS NULL))'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_quantities_non_negative',
            'CHECK (quantity_received >= 0 AND quantity_damaged >= 0 AND quantity_written_off >= 0 AND quantity_returned >= 0)'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_positive',
            'CHECK (quantity_received + quantity_damaged + quantity_written_off + quantity_returned > 0)'
        );
    }

    private function addConstraint(string $table, string $name, string $definition): void
    {
        $existing = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conname = ?', [$name]);

        if ($existing !== null) {
            return;
        }

        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$name.' '.$definition);
    }
};
