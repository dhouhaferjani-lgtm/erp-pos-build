<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only apply triggers on PostgreSQL
        // SQLite doesn't support triggers in the same way
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Create function to enforce document immutability
        // CRITICAL: Allows balance_due updates for payments
        DB::statement("
            CREATE OR REPLACE FUNCTION enforce_document_immutability()
            RETURNS TRIGGER AS $$
            BEGIN
                -- Only enforce on SEALED documents
                IF OLD.fiscal_status != 'SEALED' THEN
                    RETURN NEW;
                END IF;

                -- Allow balance_due updates (critical for payment system)
                -- Allow operational status updates (e.g., posted -> paid)
                -- Allow fiscal_status transition to VOIDED (correction mechanism)
                IF (
                    -- Check immutable fields
                    OLD.document_number IS DISTINCT FROM NEW.document_number OR
                    OLD.document_date IS DISTINCT FROM NEW.document_date OR
                    OLD.partner_id IS DISTINCT FROM NEW.partner_id OR
                    OLD.subtotal IS DISTINCT FROM NEW.subtotal OR
                    OLD.discount_amount IS DISTINCT FROM NEW.discount_amount OR
                    OLD.tax_amount IS DISTINCT FROM NEW.tax_amount OR
                    OLD.total IS DISTINCT FROM NEW.total OR
                    OLD.currency IS DISTINCT FROM NEW.currency OR
                    OLD.fiscal_hash IS DISTINCT FROM NEW.fiscal_hash OR
                    OLD.previous_hash IS DISTINCT FROM NEW.previous_hash OR
                    OLD.chain_sequence IS DISTINCT FROM NEW.chain_sequence OR
                    OLD.fiscal_category IS DISTINCT FROM NEW.fiscal_category
                ) THEN
                    RAISE EXCEPTION 'Cannot modify sealed fiscal document. Field modification not allowed on document with fiscal_status=SEALED. Document ID: %', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Allow fiscal_status change ONLY to VOIDED
                IF OLD.fiscal_status IS DISTINCT FROM NEW.fiscal_status THEN
                    IF NEW.fiscal_status != 'VOIDED' THEN
                        RAISE EXCEPTION 'Sealed document can only be voided, not changed to other fiscal status. Document ID: %', OLD.id
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        ");

        // Create trigger on documents table
        DB::statement('DROP TRIGGER IF EXISTS trg_document_immutability ON documents');
        DB::statement('
            CREATE TRIGGER trg_document_immutability
            BEFORE UPDATE ON documents
            FOR EACH ROW
            EXECUTE FUNCTION enforce_document_immutability()
        ');

        // Create function to prevent deletion of fiscal documents
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_fiscal_document_deletion()
            RETURNS TRIGGER AS $$
            BEGIN
                -- Prevent deletion of SEALED or VOIDED fiscal documents
                IF OLD.fiscal_status IN ('SEALED', 'VOIDED') THEN
                    RAISE EXCEPTION 'Cannot delete fiscal document with status %. Document ID: %', OLD.fiscal_status, OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- For non-fiscal documents, allow deletion
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        ");

        // Create trigger to prevent deletion
        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_fiscal_deletion ON documents');
        DB::statement('
            CREATE TRIGGER trg_prevent_fiscal_deletion
            BEFORE DELETE ON documents
            FOR EACH ROW
            EXECUTE FUNCTION prevent_fiscal_document_deletion()
        ');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_document_immutability ON documents');
        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_fiscal_deletion ON documents');
        DB::statement('DROP FUNCTION IF EXISTS enforce_document_immutability()');
        DB::statement('DROP FUNCTION IF EXISTS prevent_fiscal_document_deletion()');
    }
};
