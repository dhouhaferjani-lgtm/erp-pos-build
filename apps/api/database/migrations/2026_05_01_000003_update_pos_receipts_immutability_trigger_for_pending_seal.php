<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The original trigger function is prevent_receipt_modification(), registered
        // by 2026_01_08_190637_create_pos_receipts_table.php. The trigger itself
        // (enforce_receipt_immutability, BEFORE UPDATE OR DELETE) does not change —
        // only the function body is updated to accommodate the pending_seal lifecycle.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_receipt_modification() RETURNS trigger AS $$
            BEGIN
                -- Block all DELETE operations (NF525 immutability requirement)
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Cannot delete fiscally sealed receipt %. Use void operation instead.', OLD.receipt_number
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                -- UPDATE path
                IF TG_OP = 'UPDATE' THEN

                    -- Allow pending_seal -> fiscalized exactly once.
                    -- At this point fiscal_hash and chain_sequence are being written.
                    IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status = 'fiscalized' THEN
                        RETURN NEW;
                    END IF;

                    -- Allow fiscalized -> voided (existing behaviour preserved).
                    IF OLD.fiscal_status = 'fiscalized' AND NEW.fiscal_status = 'voided' THEN
                        -- Verify that only void-related fields changed (core fiscal fields must stay intact)
                        IF NEW.fiscal_hash IS DISTINCT FROM OLD.fiscal_hash OR
                           NEW.receipt_number != OLD.receipt_number OR
                           NEW.total != OLD.total OR
                           NEW.subtotal != OLD.subtotal OR
                           NEW.tax_amount != OLD.tax_amount OR
                           NEW.chain_sequence IS DISTINCT FROM OLD.chain_sequence OR
                           NEW.posted_at != OLD.posted_at THEN
                            RAISE EXCEPTION 'Cannot modify immutable fields when voiding receipt %', OLD.receipt_number
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                        RETURN NEW;
                    END IF;

                    -- Reject any other transition on a fiscalized receipt
                    IF OLD.fiscal_status = 'fiscalized' THEN
                        RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified. Immutable fields: fiscal_hash, receipt_number, totals, timestamp, chain_sequence.', OLD.receipt_number
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    -- Reject pending_seal -> anything other than fiscalized
                    IF OLD.fiscal_status = 'pending_seal'
                       AND NEW.fiscal_status NOT IN ('pending_seal', 'fiscalized') THEN
                        RAISE EXCEPTION 'pending_seal receipt % can only transition to fiscalized, got: %', OLD.receipt_number, NEW.fiscal_status
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION prevent_receipt_modification() IS 'NF525 Immutability: Prevents modification/deletion of receipts. Allows pending_seal->fiscalized and fiscalized->voided transitions only.';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Restore the original trigger function body from the create-pos-receipts migration.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_receipt_modification() RETURNS trigger AS $$
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

            COMMENT ON FUNCTION prevent_receipt_modification() IS 'NF525 Immutability: Prevents modification/deletion of receipts (allows only void operation)';
        SQL);
    }
};
