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
     * Creates pos_receipts table for immutable transaction records (NF525 TICKET events).
     * Each receipt is hash-chained to the previous receipt in the terminal's sequence.
     */
    public function up(): void
    {
        Schema::create('pos_receipts', function (Blueprint $table) {
            // Primary Key
            $table->uuid('id')->primary();

            // Multi-tenancy
            $table->uuid('tenant_id');
            $table->foreignUuid('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();
            $table->foreignUuid('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table->foreignUuid('terminal_id')
                ->constrained('pos_terminals')
                ->restrictOnDelete();

            // Receipt Identity (NF525 Compliant)
            $table->string('receipt_number', 50)->unique();
            $table->integer('chain_sequence');
            $table->integer('receipt_year');

            // Hash Chain (NF525 Critical Fields)
            $table->char('fiscal_hash', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->char('vat_breakdown_hash', 64);
            $table->char('payment_methods_hash', 64);

            // Transaction Timestamp
            $table->timestamp('posted_at');

            // Cashier
            $table->foreignUuid('cashier_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('cashier_name', 100);

            // Financial Totals (NF525 Required)
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total', 12, 2);
            $table->char('currency', 3)->default('TND');

            // Business Context
            $table->string('consumption_mode', 20)->nullable();
            $table->string('customer_name', 100)->nullable();
            $table->string('customer_identifier', 50)->nullable();

            // Void Handling (NF525 - voids create compensating entries)
            $table->boolean('is_voided')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('void_reason')->nullable();
            $table->uuid('void_receipt_id')->nullable();

            // Sync Tracking (Offline Terminals - Phase 2)
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();

            // Notes
            $table->text('notes')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes for Performance
            $table->index('tenant_id');
            $table->index('company_id');
            $table->index(['terminal_id', 'posted_at']);
            $table->index(['cashier_id', 'posted_at']);
            $table->index('posted_at');
            $table->index(['receipt_year', 'chain_sequence']);
            $table->index(['terminal_id', 'synced_at']);

            // Unique constraint for terminal sequence
            $table->unique(['terminal_id', 'receipt_year', 'chain_sequence'], 'pos_receipts_terminal_sequence');
        });

        // Add self-referential foreign key for void receipts
        Schema::table('pos_receipts', function (Blueprint $table) {
            $table->foreign('void_receipt_id')
                ->references('id')
                ->on('pos_receipts')
                ->restrictOnDelete();
        });

        // PostgreSQL-specific constraints and comments (only on PostgreSQL)
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Check Constraints
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount)');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_sequence CHECK (chain_sequence > 0)');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_year_range CHECK (receipt_year BETWEEN 2020 AND 2100)');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_hash_length CHECK (
                length(fiscal_hash) = 64 AND
                length(vat_breakdown_hash) = 64 AND
                length(payment_methods_hash) = 64
            )');
            DB::statement('ALTER TABLE pos_receipts ADD CONSTRAINT pos_receipts_void_logic CHECK (
                (is_voided = false) OR
                (is_voided = true AND voided_at IS NOT NULL AND voided_by IS NOT NULL)
            )');

            // Table and Column Comments
            DB::statement('COMMENT ON TABLE pos_receipts IS \'Immutable POS transaction records (NF525 TICKET events)\'');
            DB::statement('COMMENT ON COLUMN pos_receipts.fiscal_hash IS \'SHA-256 hash of receipt (receipt_number|timestamp|total|currency|vat_hash|payment_hash)\'');
            DB::statement('COMMENT ON COLUMN pos_receipts.previous_hash IS \'Hash of previous receipt in terminal chain (NULL for first receipt)\'');
            DB::statement('COMMENT ON COLUMN pos_receipts.consumption_mode IS \'Affects VAT rate in France: SUR_PLACE (dine-in) vs A_EMPORTER (takeaway)\'');
        }

        // Create immutability trigger
        $this->createImmutabilityTrigger();
    }

    /**
     * Create trigger to prevent receipt modification (NF525 immutability requirement)
     */
    private function createImmutabilityTrigger(): void
    {
        // PostgreSQL-specific trigger
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_receipt_modification()
                RETURNS TRIGGER AS $$
                BEGIN
                    -- Block all DELETE operations
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Cannot delete fiscally sealed receipt %. Use void operation instead.', OLD.receipt_number
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    -- Block UPDATE operations except void
                    IF TG_OP = 'UPDATE' THEN
                        -- Allow ONLY void operation
                        IF NEW.is_voided = true AND OLD.is_voided = false THEN
                            -- Verify only void-related fields changed
                            IF NEW.fiscal_hash != OLD.fiscal_hash OR
                               NEW.receipt_number != OLD.receipt_number OR
                               NEW.total != OLD.total OR
                               NEW.subtotal != OLD.subtotal OR
                               NEW.tax_amount != OLD.tax_amount OR
                               NEW.chain_sequence != OLD.chain_sequence OR
                               NEW.posted_at != OLD.posted_at THEN
                                RAISE EXCEPTION 'Cannot modify immutable fields when voiding receipt %', OLD.receipt_number
                                    USING ERRCODE = 'integrity_constraint_violation';
                            END IF;

                            -- Void operation allowed
                            RETURN NEW;
                        ELSE
                            -- All other updates blocked
                            RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified. Immutable fields: fiscal_hash, receipt_number, totals, timestamp, chain_sequence.', OLD.receipt_number
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER enforce_receipt_immutability
                    BEFORE UPDATE OR DELETE ON pos_receipts
                    FOR EACH ROW
                    EXECUTE FUNCTION prevent_receipt_modification();

                COMMENT ON FUNCTION prevent_receipt_modification() IS 'NF525 Immutability: Prevents modification/deletion of receipts (allows only void operation)';
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS enforce_receipt_immutability ON pos_receipts');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_receipt_modification()');
        Schema::dropIfExists('pos_receipts');
    }
};
