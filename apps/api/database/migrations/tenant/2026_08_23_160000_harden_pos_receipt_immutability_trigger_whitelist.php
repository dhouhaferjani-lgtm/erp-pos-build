<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Session B lane Q-6 — `prevent_receipt_modification()` becomes a
 * whitelist-with-`ELSE RAISE` instead of a fall-through.
 *
 * ## The defect this closes
 *
 * The previous body (`2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:59-155`,
 * the last `CREATE OR REPLACE` of this function) branched only on
 * `OLD.fiscal_status = 'pending_seal'` and `OLD.fiscal_status = 'fiscalized'`.
 * The column CHECK
 * (`2026_05_01_000001_prepare_pos_receipts_for_pending_seal.php:54-55`)
 * admits SIX values; for the other four —
 * `voided`, `pending_sync`, `synced`, `sync_failed` — control fell straight
 * through every guard to the bare `RETURN NEW`. At the DB layer that
 * permitted `voided -> fiscalized` (un-voiding an archived fiscal receipt,
 * which every `where('fiscal_status', Fiscalized)` consumer then counts
 * again) and free rewriting of `fiscal_hash`, `receipt_number`, `total`,
 * `subtotal`, `tax_amount`, `chain_sequence`, `posted_at` on a voided row,
 * with the hash chain still pointing at the old bytes. The
 * `fiscalized -> voided` branch's own field-integrity check only ever runs
 * ON that edge, never afterwards. This trigger is the ONLY backstop — there
 * is no application-layer state machine above it.
 *
 * Shape copied from the `fiscal_events` immutability trigger
 * (`2026_05_14_100002_create_fiscal_events_immutability.php:206-235`):
 * named transitions, `ELSE RAISE EXCEPTION` default, so a newly-admitted
 * `fiscal_status` value can never fall through silently again.
 *
 * ## Every branch of the old body, and where it went
 *
 * No allowance is dropped. Exactly two allowances are TIGHTENED, both ruled
 * by the fiscal-pos gate r1 record
 * (`docs/superpowers/reviews/2026-08-23-sb-q6-trigger-gate-r1.md`): F-1 on
 * the FK-cleanup branch and F-4 on the `sealed_hash_algorithm` branch. Both
 * are marked in the table and explained at their branch.
 *
 * | old body                                    | new body |
 * |---------------------------------------------|----------|
 * | DELETE -> always raise (`:54-57`)            | unchanged, verbatim |
 * | `pending_seal -> fiscalized` allow (`:60-62`)| `pending_seal` arm, first check |
 * | `fiscalized -> voided` + immutable-field diff (`:64-76`) | `fiscalized` arm, first check, condition and message verbatim |
 * | `fiscalized` FK-cleanup allow (`:78-96`)    | `fiscalized` arm, second check — verbatim PLUS the two `fiscal_status`/`is_voided` invariance lines (gate r1 / F-1, the one deliberate tightening; see below) |
 * | `fiscalized` one-time `sealed_hash_algorithm` allow (`:120-141`) | `fiscalized` arm, third check — same entry condition, 18-column enumeration replaced by the strictly-stronger `to_jsonb - keys` comparison (gate r1 / F-4) |
 * | `fiscalized` catch-all raise (`:143-146`)   | `fiscalized` arm, `ELSE` — same message |
 * | `pending_seal` narrow raise (`:148-152`)    | `pending_seal` arm, `ELSE` — same message |
 * | `pending_seal -> pending_seal` (unrestricted, reached the bare `RETURN NEW` at `:155`) | `pending_seal` arm, explicit `RETURN NEW` — deliberately preserved: an un-sealed receipt is still being built |
 * | the four unguarded states reaching `:155`   | NEW frozen arm (below) |
 * | anything else reaching `:155`               | NEW `ELSE RAISE` |
 * | INSERT and every non-UPDATE/DELETE op       | unchanged — the trigger is `BEFORE UPDATE OR DELETE` only and the trailing `RETURN NEW` is kept |
 *
 * ## The frozen arm (`voided`, `pending_sync`, `synced`, `sync_failed`)
 *
 * Two allowances, then raise:
 *
 * 1. A STRICT no-op: `to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD)` —
 *    EVERY column, `updated_at` included. An idempotent re-write of the same
 *    values stays legal; a Laravel `touch()`-style `updated_at`-only bump on
 *    a frozen row does NOT (pinned by
 *    `PosReceiptFrozenStateImmutabilityTriggerTest::test_a_touch_style_updated_at_only_write_on_a_voided_receipt_is_rejected`).
 *    Eloquent issues no UPDATE at all for a clean model, so ordinary model
 *    code never reaches this.
 *
 * 2. The CARRIED-FORWARD one-time `sealed_hash_algorithm` NULL -> value
 *    discriminator write, with every other column byte-identical
 *    (`updated_at` excepted, because Eloquent bumps it). This is NOT a new
 *    allowance: it is legal TODAY on these rows through the very
 *    fall-through this migration closes, it is exercised by live code —
 *    `BackfillSealedHashAlgorithmCommand::backfillTerminal()`
 *    (`app/Modules/Fiscal/Infrastructure/Commands/BackfillSealedHashAlgorithmCommand.php:176-236`)
 *    walks a `fiscal_status`/`is_voided`-UNFILTERED query and `save()`s the
 *    discriminator onto every legacy row it can classify — and it is
 *    asserted by an existing green test
 *    (`tests/Feature/Fiscal/BackfillSealedHashAlgorithmCommandTest.php:164-205`,
 *    which requires a VOIDED row to receive `legacy_pipe_v1`). Freezing
 *    these states without it would abort that command mid-run on any legacy
 *    tenant. `OLD.sealed_hash_algorithm IS NULL` makes it structurally
 *    one-time per row.
 *
 * The three sync states are frozen EXACTLY like `voided` and no transition
 * semantics are invented for them: no server code writes any of the four
 * values (`ReceiptCreationService.php:584`, `ReceiptFinalizationService.php:117`,
 * `ReceiptReturnService.php:821`, `PosCoreReceiptProjection.php:398` write
 * only `pending_seal`/`fiscalized`; `FiscalSchemaCutoverService.php:110`
 * only READS `pending_sync` in a `whereIn` filter). **Widening any of these
 * four states — including splitting the device-sync states out of
 * `fiscal_status` into their own column — requires a deliberate migration
 * that re-defines this function; it must never be done by relaxing the
 * `ELSE`.**
 *
 * ## PG's own FK cascade is a DELIBERATELY-REFUSED writer on frozen rows
 *
 * (Gate r1 / F-2 — PARENT RULING: keep the freeze.)
 *
 * The frozen arm does NOT carry the `fiscalized` arm's FK-cleanup allowance,
 * and that has a consequence beyond application code: `pos_receipts`
 * references `partners(id)` and `contacts(id)` with `ON DELETE SET NULL`, so
 * a HARD delete of a partner or contact makes PostgreSQL itself issue an
 * UPDATE against every referencing receipt. On a frozen row that UPDATE is
 * refused and the parent DELETE aborts. Under the previous body it silently
 * succeeded, nulling `partner_id` on a voided fiscal receipt.
 *
 * That refusal is intended. A fiscal archival row must fail CLOSED: the void
 * has to stay exactly as it was written, and a hard-delete path that would
 * mutate one must abort loudly rather than change an archived receipt behind
 * the operator's back. The exposure is latent today — `Partner`
 * (`app/Modules/Partner/Domain/Partner.php:88`) and `Contact`
 * (`app/Modules/Contact/Domain/Contact.php:53`) both use `SoftDeletes`, and
 * no `forceDelete()` call site anywhere in `app/` touches either model (the
 * ones that exist are terminals, media assets, documents and tenants) — so
 * no live writer hits this. Pinned by
 * `PosReceiptFrozenStateImmutabilityTriggerTest::test_hard_deleting_a_partner_referenced_by_a_frozen_receipt_is_refused`.
 * If a GDPR-style hard-erase path is ever built it must handle frozen
 * receipts explicitly (deliberate migration widening this arm, or an
 * erase strategy that does not rewrite sealed rows) — it must not be
 * "fixed" by relaxing the freeze.
 *
 * ## `down()` restores the KNOWN-VULNERABLE body — deliberately
 *
 * `down()` re-installs the previous definition VERBATIM, fall-through and
 * all: the four unguarded states, the FK-cleanup branch without its
 * `fiscal_status`/`is_voided` invariance (F-1), and the 18-column
 * enumeration (F-4). Rolling this migration back therefore RE-OPENS every
 * hole it closes. That is the correct semantic for a `down()` — it must
 * restore the prior state, not a half-hardened invention — but it means a
 * rollback is a fiscal-integrity regression, never a routine step.
 *
 * ## MIGRATION-BEARING — census obligation (S-16 pattern)
 *
 * This migration is DDL on a function only: it adds no constraint and no
 * unique index, validates no data, and CANNOT fail on existing rows
 * whatever their `fiscal_status`. It is therefore safe on non-zero counts —
 * a `CREATE OR REPLACE FUNCTION` never touches a row. The census exists to
 * size the BEHAVIOURAL exposure (which rows stop being writable), not to
 * gate the DDL:
 *
 *   SELECT fiscal_status, COUNT(*) FROM pos_receipts WHERE fiscal_status IN
 *   ('voided','pending_sync','synced','sync_failed') GROUP BY 1;
 *
 * Run per tenant database before promotion. Local fleet (10 databases:
 * `autoerp` + 9 `tenant<uuid>` DBs, PG 5433, 2026-08-23): ZERO rows in all
 * four states in every database. A non-zero staging/production count is not
 * an abort — it is the population that becomes frozen, and (for rows with
 * `sealed_hash_algorithm IS NULL AND fiscal_event_id IS NULL`) the
 * population the backfill allowance above keeps writable.
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
                    -- ========================================================
                    -- pending_seal — the receipt is not sealed yet.
                    -- Carried forward VERBATIM from the previous body:
                    --   pending_seal -> fiscalized  : allowed (seal)
                    --   pending_seal -> pending_seal: allowed, unrestricted
                    --                                 (still being built)
                    --   anything else               : raise
                    -- ========================================================
                    IF OLD.fiscal_status = 'pending_seal' THEN
                        IF NEW.fiscal_status = 'fiscalized' THEN
                            RETURN NEW;
                        ELSIF NEW.fiscal_status = 'pending_seal' THEN
                            RETURN NEW;
                        ELSE
                            RAISE EXCEPTION 'pending_seal receipt % can only transition to fiscalized, got: %', OLD.receipt_number, NEW.fiscal_status
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;

                    -- ========================================================
                    -- fiscalized — sealed. Three named allowances, then raise.
                    -- Conditions and messages carried forward VERBATIM; the
                    -- ORDER is load-bearing (the void edge is evaluated
                    -- before the FK-cleanup and discriminator branches, as
                    -- it was before).
                    -- ========================================================
                    ELSIF OLD.fiscal_status = 'fiscalized' THEN
                        -- (1) the void edge, with its immutable-field diff check.
                        IF NEW.fiscal_status = 'voided' THEN
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

                        -- (2) nulling the query-only customer FKs
                        --     (2026_05_26_100001_allow_pos_receipt_fk_cleanup.php).
                        --
                        --     GATE r1 / F-1 (Critical, folded into lane Q-6):
                        --     the two `fiscal_status` / `is_voided` lines
                        --     below are NEW. Without them this branch guarded
                        --     12 columns but neither of those two, so a
                        --     status downgrade could ride along on an
                        --     otherwise-legitimate customer-FK cleanup:
                        --     fiscalized -> pending_seal (where column edits
                        --     are unrestricted) -> rewrite totals + forge
                        --     fiscal_hash -> back to fiscalized. The gate
                        --     reproduced that full round trip with no
                        --     exception at any step; it was pre-existing
                        --     across all four prior bodies of this function,
                        --     copied forward each time. It is the ONLY
                        --     behavioural change this migration makes to the
                        --     `fiscalized` arm's allowances, and it brings
                        --     this branch to parity with the §6.2 branch
                        --     below, which has pinned both columns since
                        --     final-review condition I-1.
                        IF (OLD.partner_id IS NOT NULL OR OLD.contact_id IS NOT NULL)
                           AND NEW.partner_id IS NULL
                           AND NEW.contact_id IS NULL
                           AND NEW.fiscal_status = OLD.fiscal_status
                           AND NEW.is_voided IS NOT DISTINCT FROM OLD.is_voided
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

                        -- (3) v3-refund-chain-integration spec §6.2: the
                        --     one-time sealed_hash_algorithm NULL -> value
                        --     transition ALONE.
                        --
                        --     GATE r1 / F-4 (folded): the previous body
                        --     enumerated 18 columns here, which left every
                        --     column NOT on that list rewritable alongside
                        --     the discriminator — `previous_hash` above all,
                        --     the chain link the v3 verifier reads, on every
                        --     pre-feature row (`OLD.sealed_hash_algorithm IS
                        --     NULL` matches the entire installed base). The
                        --     `to_jsonb - keys` comparison below is the same
                        --     technique the frozen arm uses: it subsumes all
                        --     18 enumerated columns, closes the rest by
                        --     construction, and keeps covering columns added
                        --     to pos_receipts in future without editing this
                        --     function. Strictly stronger than the list it
                        --     replaces, so FINAL-REVIEW CONDITION 1 (I-1)
                        --     still holds a fortiori — see
                        --     PosReceiptImmutabilityTriggerSealedHashAlgorithmTest,
                        --     which stays green unchanged.
                        --     `updated_at` is excluded because Eloquent bumps
                        --     it on save(); that is the exact statement
                        --     BackfillSealedHashAlgorithmCommand issues.
                        IF OLD.sealed_hash_algorithm IS NULL
                           AND NEW.sealed_hash_algorithm IS NOT NULL
                           AND (to_jsonb(NEW) - 'sealed_hash_algorithm' - 'updated_at')
                               IS NOT DISTINCT FROM
                               (to_jsonb(OLD) - 'sealed_hash_algorithm' - 'updated_at') THEN
                            RETURN NEW;
                        END IF;

                        RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified. Immutable fields: fiscal_hash, receipt_number, totals, timestamp, chain_sequence.', OLD.receipt_number
                            USING ERRCODE = 'integrity_constraint_violation';

                    -- ========================================================
                    -- FROZEN archival states (Session B lane Q-6).
                    -- voided       : NF525 end-state — the void must stay
                    --                auditable, so the row stays frozen and
                    --                the Z totals/chain keep reconciling.
                    -- pending_sync / synced / sync_failed : device-sync
                    --                values the CHECK admits but no server
                    --                code writes. Frozen the same way; NO
                    --                transition semantics invented.
                    -- Two allowances only, then raise. See the class
                    -- docblock for why (2) is a carry-forward, not a
                    -- widening.
                    -- ========================================================
                    ELSIF OLD.fiscal_status IN ('voided', 'pending_sync', 'synced', 'sync_failed') THEN
                        -- (1) strict no-op: EVERY column, updated_at included.
                        --     to_jsonb() rather than a record comparison so
                        --     the guard keeps working for column types with
                        --     no equality operator, and so columns added to
                        --     pos_receipts in future are covered without
                        --     touching this function.
                        IF to_jsonb(NEW) IS NOT DISTINCT FROM to_jsonb(OLD) THEN
                            RETURN NEW;
                        END IF;

                        -- (2) the carried-forward one-time
                        --     sealed_hash_algorithm NULL -> value write.
                        --     updated_at is excluded because Eloquent bumps
                        --     it on save(); nothing else may differ.
                        IF OLD.sealed_hash_algorithm IS NULL
                           AND NEW.sealed_hash_algorithm IS NOT NULL
                           AND (to_jsonb(NEW) - 'sealed_hash_algorithm' - 'updated_at')
                               IS NOT DISTINCT FROM
                               (to_jsonb(OLD) - 'sealed_hash_algorithm' - 'updated_at') THEN
                            RETURN NEW;
                        END IF;

                        RAISE EXCEPTION 'Receipt % is frozen in fiscal_status ''%'' and cannot be modified (only a strict no-op or the one-time sealed_hash_algorithm backfill is permitted).', OLD.receipt_number, OLD.fiscal_status
                            USING ERRCODE = 'integrity_constraint_violation';

                    -- ========================================================
                    -- ELSE — a fiscal_status this function has never been
                    -- taught about. Fail CLOSED: the previous body returned
                    -- NEW here, which is exactly how the four states above
                    -- went unguarded for eight months.
                    -- ========================================================
                    ELSE
                        RAISE EXCEPTION 'Receipt %: fiscal_status ''%'' has no UPDATE policy in prevent_receipt_modification() — refusing the UPDATE. Widening requires a deliberate migration.', OLD.receipt_number, OLD.fiscal_status
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION prevent_receipt_modification() IS 'NF525 Immutability: whitelist-with-ELSE-RAISE. Permits the pending_seal seal/self-edit, the fiscalized void transition, nulling query-only customer FKs, and the one-time sealed_hash_algorithm backfill; freezes voided/pending_sync/synced/sync_failed (strict no-op or that one-time backfill only); every other fiscal_status raises (Session B lane Q-6).';
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Revert to the exact prior definition
        // (2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php's up()),
        // fall-through and all.
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
                       AND NEW.customer_name IS NOT DISTINCT FROM OLD.customer_name
                       AND NEW.customer_identifier IS NOT DISTINCT FROM OLD.customer_identifier
                       AND NEW.partner_id IS NOT DISTINCT FROM OLD.partner_id
                       AND NEW.contact_id IS NOT DISTINCT FROM OLD.contact_id
                       AND NEW.fiscal_status = OLD.fiscal_status
                       AND NEW.is_voided IS NOT DISTINCT FROM OLD.is_voided
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
};
