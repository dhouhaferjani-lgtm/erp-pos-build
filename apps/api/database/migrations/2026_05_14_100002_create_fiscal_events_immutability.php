<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 8 — `fiscal_events` immutability triggers (server PostgreSQL).
 *
 * Spec §3.3:
 *   - BEFORE UPDATE — `RAISE EXCEPTION` unless only the allowed columns changed
 *     (`payload`, `payload_parse_status`, `integrity_status`,
 *     `integrity_exception_class`, `integrity_exception_reason`,
 *     `integrity_resolved_at`, `integrity_resolved_by`).
 *   - State transitions within the allowed set:
 *       * `payload_parse_status` — `pending → parsed`, `pending → failed`, and
 *         the gated `failed → parsed` parse-failure resume path (plan-review
 *         BLOCKER fix — see below). Nothing else.
 *       * `payload` — write-once: settable only when `payload_parse_status`
 *         goes `pending → parsed` or `failed → parsed` (resume).
 *       * `integrity_status` — `verified → quarantined` (ingestor) or
 *         `quarantined → verified` (resolver, which must also set
 *         `integrity_resolved_at` AND `integrity_resolved_by`). Nothing else.
 *   - BEFORE DELETE — always `RAISE EXCEPTION`.
 *   - BEFORE TRUNCATE (statement-level) — always `RAISE EXCEPTION`.
 *   - `REVOKE TRUNCATE ON fiscal_events FROM <application_role>` to remove the
 *     ambient capability before any privileged break-glass procedure runs.
 *
 * The `failed → parsed` resume transition (spec §7.5 / Task 24) is the v1
 * plan-review BLOCKER fix: a quarantined `canonical_parse_failure` event must
 * be resolvable in one atomic UPDATE (write payload, flip parse_status to
 * parsed, flip integrity to verified, stamp resolved_at/resolved_by). Building
 * the named transition here means Task 24's resolver is composable from day
 * one, not discovered as a contradiction in Task 24.
 *
 * Modelled on `prevent_receipt_modification()` in
 * `2026_05_01_000003_update_pos_receipts_immutability_trigger_for_pending_seal.php`
 * — the project's established pattern for transition-explicit, raise-otherwise
 * PG triggers.
 *
 * Break-glass procedure documented in
 * `apps/api/docs/runbooks/fiscal-events-break-glass.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fiscal_events_immutability_trigger() RETURNS trigger AS $$
            BEGIN
                -- ============================================================
                -- DELETE: always rejected. The ledger is append-only by law.
                -- ============================================================
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'fiscal_events row % cannot be deleted — append-only ledger (spec §3.3). Use the DBA break-glass runbook.', OLD.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                -- ============================================================
                -- UPDATE: only the allowed-column whitelist may change, and
                -- only via the named transitions. Everything else raises.
                -- ============================================================
                IF TG_OP = 'UPDATE' THEN

                    -- Step 1: no column outside the allowed whitelist may change.
                    -- Identity / chain / signature / canonical / tenancy columns are frozen
                    -- the instant the row is inserted by OutboxIngestor.
                    IF NEW.id                       IS DISTINCT FROM OLD.id
                       OR NEW.tenant_id              IS DISTINCT FROM OLD.tenant_id
                       OR NEW.company_id             IS DISTINCT FROM OLD.company_id
                       OR NEW.terminal_id            IS DISTINCT FROM OLD.terminal_id
                       OR NEW.operator_id            IS DISTINCT FROM OLD.operator_id
                       OR NEW.event_type             IS DISTINCT FROM OLD.event_type
                       OR NEW.event_version          IS DISTINCT FROM OLD.event_version
                       OR NEW.signature_version      IS DISTINCT FROM OLD.signature_version
                       OR NEW.sequence_number        IS DISTINCT FROM OLD.sequence_number
                       OR NEW.event_time_device      IS DISTINCT FROM OLD.event_time_device
                       OR NEW.business_date          IS DISTINCT FROM OLD.business_date
                       OR NEW.last_server_time_seen  IS DISTINCT FROM OLD.last_server_time_seen
                       OR NEW.server_received_at     IS DISTINCT FROM OLD.server_received_at
                       OR NEW.reference_event_id     IS DISTINCT FROM OLD.reference_event_id
                       OR NEW.reference_document_id  IS DISTINCT FROM OLD.reference_document_id
                       OR NEW.source_event_class     IS DISTINCT FROM OLD.source_event_class
                       OR NEW.source_event_id        IS DISTINCT FROM OLD.source_event_id
                       OR NEW.partner_id             IS DISTINCT FROM OLD.partner_id
                       OR NEW.partner_identity_snapshot IS DISTINCT FROM OLD.partner_identity_snapshot
                       OR NEW.canonical_bytes        IS DISTINCT FROM OLD.canonical_bytes
                       OR NEW.previous_hash          IS DISTINCT FROM OLD.previous_hash
                       OR NEW.current_hash           IS DISTINCT FROM OLD.current_hash
                       OR NEW.signature_status       IS DISTINCT FROM OLD.signature_status
                       OR NEW.signature_algorithm    IS DISTINCT FROM OLD.signature_algorithm
                       OR NEW.signature_value        IS DISTINCT FROM OLD.signature_value
                       OR NEW.signature_counter      IS DISTINCT FROM OLD.signature_counter
                       OR NEW.signature_provider     IS DISTINCT FROM OLD.signature_provider
                       OR NEW.signing_device_id      IS DISTINCT FROM OLD.signing_device_id
                       OR NEW.certificate_id         IS DISTINCT FROM OLD.certificate_id
                       OR NEW.signed_payload_ref     IS DISTINCT FROM OLD.signed_payload_ref
                       OR NEW.time_source_value      IS DISTINCT FROM OLD.time_source_value
                       OR NEW.time_format            IS DISTINCT FROM OLD.time_format
                       OR NEW.provider_transaction_id IS DISTINCT FROM OLD.provider_transaction_id
                       OR NEW.created_at             IS DISTINCT FROM OLD.created_at
                    THEN
                        RAISE EXCEPTION 'fiscal_events row %: only payload / payload_parse_status / integrity_status / integrity_exception_class / integrity_exception_reason / integrity_resolved_at / integrity_resolved_by may change (spec §3.3).', OLD.id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    -- Step 2: payload_parse_status transition validation.
                    --   pending  -> parsed   (normal parse success)
                    --   pending  -> failed   (normal parse failure)
                    --   failed   -> parsed   (parse-failure resume — see Step 3)
                    --   X        -> X        (no-op — always allowed)
                    -- Anything else raises.
                    IF NEW.payload_parse_status IS DISTINCT FROM OLD.payload_parse_status THEN
                        IF NOT (
                            (OLD.payload_parse_status = 'pending' AND NEW.payload_parse_status = 'parsed')
                            OR (OLD.payload_parse_status = 'pending' AND NEW.payload_parse_status = 'failed')
                            OR (OLD.payload_parse_status = 'failed'  AND NEW.payload_parse_status = 'parsed')
                        ) THEN
                            RAISE EXCEPTION 'fiscal_events row %: payload_parse_status transition % -> % is not allowed (spec §3.3).', OLD.id, OLD.payload_parse_status, NEW.payload_parse_status
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;

                        -- Step 3: the `failed -> parsed` resume transition is gated.
                        -- Allowed ONLY when ALL of these hold in the same UPDATE:
                        --   * OLD.payload IS NULL                                (parse never produced a payload)
                        --   * NEW.payload IS NOT NULL                            (the resolver supplies one)
                        --   * OLD.integrity_exception_class = 'canonical_parse_failure'
                        --   * OLD.integrity_status = 'quarantined'
                        --   * NEW.integrity_status = 'verified'
                        --   * NEW.integrity_resolved_at IS NOT NULL
                        --   * NEW.integrity_resolved_by IS NOT NULL
                        -- This is the plan-review BLOCKER fix — Task 24's resolver depends on it.
                        IF OLD.payload_parse_status = 'failed' AND NEW.payload_parse_status = 'parsed' THEN
                            IF NOT (
                                OLD.payload IS NULL
                                AND NEW.payload IS NOT NULL
                                AND OLD.integrity_exception_class = 'canonical_parse_failure'
                                AND OLD.integrity_status = 'quarantined'
                                AND NEW.integrity_status = 'verified'
                                AND NEW.integrity_resolved_at IS NOT NULL
                                AND NEW.integrity_resolved_by IS NOT NULL
                            ) THEN
                                RAISE EXCEPTION 'fiscal_events row %: failed -> parsed transition only allowed when resolving a quarantined canonical_parse_failure to verified, with payload + resolution stamps in the same UPDATE (spec §3.3 / §7.5).', OLD.id
                                    USING ERRCODE = 'integrity_constraint_violation';
                            END IF;
                        END IF;
                    END IF;

                    -- Step 4: payload is write-once. It may be set only when
                    -- payload_parse_status flips pending -> parsed OR failed -> parsed
                    -- (the gated resume above). A second payload write raises.
                    IF NEW.payload IS DISTINCT FROM OLD.payload THEN
                        IF OLD.payload IS NOT NULL THEN
                            RAISE EXCEPTION 'fiscal_events row %: payload is write-once (spec §3.3).', OLD.id
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;

                        -- OLD.payload was NULL — the only legal way to write it is in
                        -- a parse-status transition to 'parsed'.
                        IF NOT (
                            (OLD.payload_parse_status = 'pending' AND NEW.payload_parse_status = 'parsed')
                            OR (OLD.payload_parse_status = 'failed'  AND NEW.payload_parse_status = 'parsed')
                        ) THEN
                            RAISE EXCEPTION 'fiscal_events row %: payload may only be written when payload_parse_status transitions to ''parsed'' (spec §3.3).', OLD.id
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                    END IF;

                    -- Step 5: integrity_status transition validation.
                    --   verified    -> quarantined           (ingestor flagged)
                    --   quarantined -> verified              (resolver — must stamp resolved_at + resolved_by)
                    --   X           -> X                     (no-op — always allowed)
                    -- Anything else raises.
                    IF NEW.integrity_status IS DISTINCT FROM OLD.integrity_status THEN
                        IF OLD.integrity_status = 'verified' AND NEW.integrity_status = 'quarantined' THEN
                            -- ingestor path — no further required fields here
                            NULL;
                        ELSIF OLD.integrity_status = 'quarantined' AND NEW.integrity_status = 'verified' THEN
                            IF NEW.integrity_resolved_at IS NULL OR NEW.integrity_resolved_by IS NULL THEN
                                RAISE EXCEPTION 'fiscal_events row %: quarantined -> verified requires integrity_resolved_at AND integrity_resolved_by (spec §3.3).', OLD.id
                                    USING ERRCODE = 'integrity_constraint_violation';
                            END IF;
                        ELSE
                            RAISE EXCEPTION 'fiscal_events row %: integrity_status transition % -> % is not allowed (spec §3.3).', OLD.id, OLD.integrity_status, NEW.integrity_status
                                USING ERRCODE = 'integrity_constraint_violation';
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                -- ============================================================
                -- TRUNCATE (statement-level): always rejected.
                -- ============================================================
                IF TG_OP = 'TRUNCATE' THEN
                    RAISE EXCEPTION 'fiscal_events cannot be truncated — append-only ledger (spec §3.3). Use the DBA break-glass runbook.'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            COMMENT ON FUNCTION fiscal_events_immutability_trigger() IS
                'Spec §3.3: fiscal_events immutability — allowed-column whitelist + named state transitions including the failed->parsed parse-failure resume (Task 24).';
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fiscal_events_immutability_update
            BEFORE UPDATE ON fiscal_events
            FOR EACH ROW
            EXECUTE FUNCTION fiscal_events_immutability_trigger();

            CREATE TRIGGER fiscal_events_immutability_delete
            BEFORE DELETE ON fiscal_events
            FOR EACH ROW
            EXECUTE FUNCTION fiscal_events_immutability_trigger();

            CREATE TRIGGER fiscal_events_immutability_truncate
            BEFORE TRUNCATE ON fiscal_events
            FOR EACH STATEMENT
            EXECUTE FUNCTION fiscal_events_immutability_trigger();
        SQL);

        // REVOKE TRUNCATE ON fiscal_events FROM <application_role>. We scope to
        // the connection user because that is the role the application logs in
        // as; the BEFORE TRUNCATE trigger is the belt, this is the suspenders.
        // If we cannot determine the role safely we log a note and skip — the
        // runbook documents the manual REVOKE as a fallback.
        $appRole = config('database.connections.pgsql.username');

        if (is_string($appRole) && $appRole !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $appRole) === 1) {
            DB::statement('REVOKE TRUNCATE ON fiscal_events FROM "'.$appRole.'"');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fiscal_events_immutability_truncate ON fiscal_events;
            DROP TRIGGER IF EXISTS fiscal_events_immutability_delete   ON fiscal_events;
            DROP TRIGGER IF EXISTS fiscal_events_immutability_update   ON fiscal_events;
            DROP FUNCTION IF EXISTS fiscal_events_immutability_trigger();
        SQL);

        $appRole = config('database.connections.pgsql.username');

        if (is_string($appRole) && $appRole !== '' && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $appRole) === 1) {
            // Restore the default ambient capability on `down()`.
            DB::statement('GRANT TRUNCATE ON fiscal_events TO "'.$appRole.'"');
        }
    }
};
