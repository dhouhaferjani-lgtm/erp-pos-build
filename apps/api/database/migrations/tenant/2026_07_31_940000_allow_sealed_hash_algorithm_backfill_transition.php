<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v3-refund-chain-integration spec §6.2 — ⚖️ orchestrator ruling, implemented
 * exactly: one-time, trigger-permitted NULL→value transition for
 * `pos_receipts.sealed_hash_algorithm`.
 *
 * `prevent_receipt_modification()` rejects any UPDATE on a
 * `fiscal_status = 'fiscalized'` row outside its existing permitted
 * branches — but the seal-discriminator backfill command (§6, T13) MUST
 * UPDATE already-fiscalized rows to set this new column. This migration
 * re-defines the trigger function ONE MORE TIME (the repository's own
 * established pattern for evolving this trigger — three prior migrations
 * have done exactly this: `2026_01_08_190637`, `2026_05_01_000003`,
 * `2026_05_26_100001`), preserving EVERY existing branch VERBATIM (copied
 * from the live `2026_05_26_100001_allow_pos_receipt_fk_cleanup.php`
 * definition) and adding exactly one new permitted branch.
 *
 * The new branch permits ONLY a `NULL` → non-`NULL` transition on
 * `sealed_hash_algorithm` alone (the `OLD.sealed_hash_algorithm IS NULL`
 * guard makes it structurally one-time per row, not merely one-time by
 * convention — a second attempt to change an already-set value is REJECTED
 * by falling through to the existing `fiscal_status = 'fiscalized'` catch-all
 * exception) while every other fiscal column remains exactly as immutable
 * as it is today.
 *
 * §10.4 (rollout's no-rewrite prohibition) is narrowed, not removed: never
 * rewrite/backfill/re-derive any `fiscal_events` row, and never modify any
 * `pos_receipts` column EXCEPT this one-time `sealed_hash_algorithm`
 * transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_receipt_modification() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Cannot delete fiscally sealed receipt %. Use void operation instead.', OLD.receipt_number
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status = 'fiscalized' THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.fiscal_status = 'fiscalized' AND NEW.fiscal_status = 'voided' THEN
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

                    IF OLD.fiscal_status = 'fiscalized'
                       AND (OLD.partner_id IS NOT NULL OR OLD.contact_id IS NOT NULL)
                       AND NEW.partner_id IS NULL
                       AND NEW.contact_id IS NULL
                       AND NEW.fiscal_hash IS NOT DISTINCT FROM OLD.fiscal_hash
                       AND NEW.receipt_number = OLD.receipt_number
                       AND NEW.total = OLD.total
                       AND NEW.subtotal = OLD.subtotal
                       AND NEW.tax_amount = OLD.tax_amount
                       AND NEW.chain_sequence IS NOT DISTINCT FROM OLD.chain_sequence
                       AND NEW.posted_at = OLD.posted_at
                       AND NEW.vat_breakdown_hash IS NOT DISTINCT FROM OLD.vat_breakdown_hash
                       AND NEW.payment_methods_hash IS NOT DISTINCT FROM OLD.payment_methods_hash
                       AND NEW.customer_name IS NOT DISTINCT FROM OLD.customer_name
                       AND NEW.customer_identifier IS NOT DISTINCT FROM OLD.customer_identifier
                       AND NEW.canonical_bytes IS NOT DISTINCT FROM OLD.canonical_bytes
                       AND NEW.fiscal_event_id IS NOT DISTINCT FROM OLD.fiscal_event_id THEN
                        RETURN NEW;
                    END IF;

                    -- v3-refund-chain-integration spec §6.2: one-time,
                    -- trigger-permitted NULL -> value transition on
                    -- sealed_hash_algorithm ALONE. Every other fiscal column
                    -- must be byte-identical (IS NOT DISTINCT FROM handles
                    -- NULL-safe comparison the same way every branch above
                    -- does). The OLD.sealed_hash_algorithm IS NULL guard
                    -- makes this structurally one-time per row: a second
                    -- attempt to change an already-set value does NOT match
                    -- this branch and falls through to the catch-all
                    -- exception below.
                    IF OLD.fiscal_status = 'fiscalized'
                       AND OLD.sealed_hash_algorithm IS NULL
                       AND NEW.sealed_hash_algorithm IS NOT NULL
                       AND NEW.fiscal_hash IS NOT DISTINCT FROM OLD.fiscal_hash
                       AND NEW.receipt_number = OLD.receipt_number
                       AND NEW.total = OLD.total
                       AND NEW.subtotal = OLD.subtotal
                       AND NEW.tax_amount = OLD.tax_amount
                       AND NEW.chain_sequence IS NOT DISTINCT FROM OLD.chain_sequence
                       AND NEW.posted_at = OLD.posted_at
                       AND NEW.vat_breakdown_hash IS NOT DISTINCT FROM OLD.vat_breakdown_hash
                       AND NEW.payment_methods_hash IS NOT DISTINCT FROM OLD.payment_methods_hash
                       AND NEW.canonical_bytes IS NOT DISTINCT FROM OLD.canonical_bytes
                       AND NEW.fiscal_event_id IS NOT DISTINCT FROM OLD.fiscal_event_id THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.fiscal_status = 'fiscalized' THEN
                        RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified. Immutable fields: fiscal_hash, receipt_number, totals, timestamp, chain_sequence.', OLD.receipt_number
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.fiscal_status = 'pending_seal'
                       AND NEW.fiscal_status NOT IN ('pending_seal', 'fiscalized') THEN
                        RAISE EXCEPTION 'pending_seal receipt % can only transition to fiscalized, got: %', OLD.receipt_number, NEW.fiscal_status
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION prevent_receipt_modification() IS 'NF525 Immutability: prevents receipt delete and fiscal-field updates; permits pending seal, void transition, nulling query-only customer FKs, and the one-time sealed_hash_algorithm backfill transition (spec 2026-07-31-v3-refund-chain-integration.md sect6.2).';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Revert to the exact prior definition
        // (2026_05_26_100001_allow_pos_receipt_fk_cleanup.php's up()).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_receipt_modification() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Cannot delete fiscally sealed receipt %. Use void operation instead.', OLD.receipt_number
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status = 'fiscalized' THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.fiscal_status = 'fiscalized' AND NEW.fiscal_status = 'voided' THEN
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

                    IF OLD.fiscal_status = 'fiscalized'
                       AND (OLD.partner_id IS NOT NULL OR OLD.contact_id IS NOT NULL)
                       AND NEW.partner_id IS NULL
                       AND NEW.contact_id IS NULL
                       AND NEW.fiscal_hash IS NOT DISTINCT FROM OLD.fiscal_hash
                       AND NEW.receipt_number = OLD.receipt_number
                       AND NEW.total = OLD.total
                       AND NEW.subtotal = OLD.subtotal
                       AND NEW.tax_amount = OLD.tax_amount
                       AND NEW.chain_sequence IS NOT DISTINCT FROM OLD.chain_sequence
                       AND NEW.posted_at = OLD.posted_at
                       AND NEW.vat_breakdown_hash IS NOT DISTINCT FROM OLD.vat_breakdown_hash
                       AND NEW.payment_methods_hash IS NOT DISTINCT FROM OLD.payment_methods_hash
                       AND NEW.customer_name IS NOT DISTINCT FROM OLD.customer_name
                       AND NEW.customer_identifier IS NOT DISTINCT FROM OLD.customer_identifier
                       AND NEW.canonical_bytes IS NOT DISTINCT FROM OLD.canonical_bytes
                       AND NEW.fiscal_event_id IS NOT DISTINCT FROM OLD.fiscal_event_id THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.fiscal_status = 'fiscalized' THEN
                        RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified. Immutable fields: fiscal_hash, receipt_number, totals, timestamp, chain_sequence.', OLD.receipt_number
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF OLD.fiscal_status = 'pending_seal'
                       AND NEW.fiscal_status NOT IN ('pending_seal', 'fiscalized') THEN
                        RAISE EXCEPTION 'pending_seal receipt % can only transition to fiscalized, got: %', OLD.receipt_number, NEW.fiscal_status
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION prevent_receipt_modification() IS 'NF525 Immutability: prevents receipt delete and fiscal-field updates; permits pending seal, void transition, and nulling query-only customer FKs.';
        SQL);
    }
};
