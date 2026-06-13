/**
 * `ChainRecoveryService.recordBreakAndRestart()` — the device's chain-recovery
 * authoring path.
 *
 * Authority: spec v7 §9 + plan §1890–1948 (Task 25).
 *
 * On a local chain break the terminal continues operating in a recorded
 * `degraded` mode. Recovery is **two chained incident events** (NOT
 * "signed" — no signature provider in Phase 1):
 *
 *   1. `CHAIN_BREAK_DETECTED` — `{ reason, last_good_sequence,
 *      last_good_hash, offending_record_reference }`
 *   2. `CHAIN_RESTART` — `{ new_genesis_reference, last_good_anchor,
 *      operator_authorization_evidence, provenance_link }`
 *
 * Both are ordinary `fiscal_events` rows (NOT a special schema). The
 * broken segment is **never deleted** — it stays in `fiscal_events` so
 * the verifier (§15) + JET export can surface it forensically.
 *
 * The two payload shapes mirror the server-side PHP DTOs at
 * `apps/api/app/Modules/Fiscal/Domain/DTOs/ChainBreakDetectedPayload.php`
 * and `ChainRestartPayload.php` (cross-language drift gate per the
 * Task 14 standing pattern: golden vectors keep the payload key sets +
 * monetary types in lockstep across PHP and TS).
 *
 * **What this service owns** (round-2):
 *
 *   - **Transactional atomicity** of the two recovery appends + the
 *     `terminal_state.fiscal_chain_status = 'degraded'` flip. All three
 *     mutations run inside a single SQLite transaction the service
 *     opens (BEGIN) and commits (COMMIT) — or rolls back (ROLLBACK) on
 *     any failure. Closes Task 25 round-1 Codex T25-B1.
 *   - **Degraded-mode flag recording** on `terminal_state` — `'healthy'`
 *     before, `'degraded'` after a successful recovery emission. Stored
 *     in a v38 column with a CHECK-constrained allowed value set. Closes
 *     Task 25 round-1 Codex T25-P1.
 *   - **Deterministic emission order** — CHAIN_BREAK_DETECTED then
 *     CHAIN_RESTART, with the RESTART's `provenance_link.chain_break_event_id`
 *     pointing back to the BREAK row's UUID so the restart is forensically
 *     traceable to its own break (not a sibling).
 *   - **Payload shape compliance** — every payload key matches the PHP
 *     `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` set. The runtime
 *     validation of hash + non-empty-assoc constraints lives in
 *     `FiscalEventEngine.validateRequestPayload()` (Task 25 round-2
 *     extension; mirrors PHP validator exactly).
 *
 * **What this service does NOT own**:
 *
 *   - The decision *when* to call (callers detect the break via §15
 *     verifier output or local structural checks).
 *   - Authoring the structured offending-reference context (callers
 *     supply it — see `ChainBreakAndRestartRequest.offending_reference`).
 *     Round-2 rejects an absent/empty `offending_reference` — synthetic
 *     `{kind: 'unknown_offender'}` placeholders are NOT manufactured at
 *     this layer (Opus F3 / Codex T25-P3 convergent fix). The caller
 *     must supply a real structured shape — e.g. `{kind: 'sequence_gap',
 *     expected, found}` for verifier-detected gaps, or
 *     `{kind: 'hash_mismatch', at_sequence, observed_previous_hash}` for
 *     hash-chain breaks. Forcing the call site to surface what triggered
 *     the break keeps the immutable chain row from carrying manufactured
 *     forensic context.
 */

import type Database from '@tauri-apps/plugin-sql';

import {
  FiscalEventEngine,
  type FiscalEventAppendResult,
  type SqlSurface,
} from './FiscalEventEngine';
import { withWriteTransaction } from '@/lib/db/writeGate';

/**
 * Caller-supplied input describing the break + the restart authorization.
 *
 * `last_good_sequence` / `last_good_hash` identify the last verifiable
 * anchor on the chain. `offending_reference` is a REQUIRED, non-empty
 * structured object identifying WHAT triggered the break — typically
 * `{kind: 'sequence_gap', expected, found}` for verifier-detected gaps
 * or `{kind: 'hash_mismatch', at_sequence, observed_previous_hash}` for
 * hash-chain breaks. The service refuses to manufacture forensic
 * context: an absent or empty reference throws `InvalidArgumentException`
 * (round-2 Opus F3 / Codex T25-P3 convergent fix). `operator_authorization_evidence`
 * captures who authorized the restart (manager id + role + reason code +
 * any out-of-band attestation like a manager-PIN audit id).
 */
export interface ChainBreakAndRestartRequest {
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  /** UTC ISO-8601 second precision — applied to both recovery events. */
  event_time_device: string;
  /** Local business date — `'YYYY-MM-DD'`. */
  business_date: string;
  /** Free-form classifier; e.g. `'gap'`, `'hash_mismatch'`, `'sequence_conflict'`. */
  reason: string;
  /** The last sequence_number on the broken chain that still verifies. */
  last_good_sequence: number;
  /** The 64-char lowercase hex `current_hash` at `last_good_sequence`. */
  last_good_hash: string;
  /**
   * REQUIRED non-empty structured identification of what triggered the
   * break. The service does NOT manufacture a placeholder when omitted —
   * the caller (verifier output, structural-check site) MUST surface
   * what it observed. Throws `InvalidArgumentException` if missing,
   * non-object, or an empty object.
   */
  offending_reference: Record<string, unknown>;
  /** `{ user_id, role, reason_code, manager_pin_attestation, ... }`. */
  operator_authorization_evidence: Record<string, unknown>;
}

/**
 * Result of a successful chain-recovery emission. Both events are
 * returned so the caller can update forensic logs / sync metadata
 * against the canonical event ids. `terminal_state.fiscal_chain_status`
 * is flipped to `'degraded'` inside the same transaction as the appends.
 */
export interface ChainRecoveryResult {
  break: FiscalEventAppendResult;
  restart: FiscalEventAppendResult;
}

/**
 * Thrown when `recordBreakAndRestart()` is called with an absent, non-object,
 * or empty `offending_reference`. The CHAIN_BREAK_DETECTED row is immutable
 * — the service refuses to manufacture forensic context so the caller is
 * forced to surface a real structured observation.
 */
export class MissingOffendingReferenceError extends Error {
  constructor(reason: string) {
    super(
      `ChainRecoveryService.recordBreakAndRestart: offending_reference is required and must be a non-empty object — ${reason}. ` +
        'The service does not manufacture placeholders (round-2 Opus F3 / Codex T25-P3). Callers should supply a structured shape ' +
        "(e.g. {kind: 'sequence_gap', expected, found} or {kind: 'hash_mismatch', at_sequence, observed_previous_hash}).",
    );
    this.name = 'MissingOffendingReferenceError';
  }
}

export class ChainRecoveryService {
  constructor(private readonly engine: FiscalEventEngine) {}

  /**
   * Author CHAIN_BREAK_DETECTED then CHAIN_RESTART against the SAME
   * `(tenant, terminal)` chain AND flip `terminal_state.fiscal_chain_status`
   * to `'degraded'` — all three mutations inside a single SQLite
   * transaction. The two events are emitted via `FiscalEventEngine.append()`
   * so they participate in the usual chain head advance, hash linkage,
   * and idempotency mechanics.
   *
   * **Transactional atomicity (round-2 — closes Codex T25-B1).** The
   * service opens `BEGIN`, runs the two appends + the degraded-flag
   * update, and commits with `COMMIT`. On ANY failure it issues
   * `ROLLBACK` and re-throws so the chain is never left in a
   * half-recovery state. SQLite's auto-commit was the round-1 hazard:
   * each `engine.append()` would otherwise commit on its own, and a
   * failure on the second append would leave the first append
   * permanently on the chain with no restart row.
   *
   * **Degraded mode (round-2 — closes Codex T25-P1).** The terminal
   * remains operational after a recovery emission but is flagged
   * `'degraded'` so the UI / sync / verifier surfaces can pattern-match
   * on the column to elevate the chain-break to operator attention.
   * Flipped inside the same transaction so the flag tracks the chain
   * mutations 1:1.
   *
   * **Provenance link.** CHAIN_RESTART's `provenance_link.chain_break_event_id`
   * is set to the UUID of the CHAIN_BREAK_DETECTED event we just
   * appended — guarantees the restart is forensically traceable to its
   * own break, not a sibling chain break.
   *
   * @throws MissingOffendingReferenceError when `offending_reference`
   *         is missing, not an object, or an empty object.
   */
  async recordBreakAndRestart(
    _tx: Database | SqlSurface,
    request: ChainBreakAndRestartRequest,
  ): Promise<ChainRecoveryResult> {
    assertOffendingReference(request.offending_reference);

    // Single-writer architecture: the two appends + the degraded flip run as
    // ONE exclusive write-gate transaction on the single connection (fiscal
    // lane). The `tx` parameter remains the caller's read handle; writes
    // must not issue BEGIN through the pooled plugin (see writeGate.ts).
    return withWriteTransaction('fiscal', async (txw) => {
      const breakResult = await this.engine.append(txw, {
        event_type: 'CHAIN_BREAK_DETECTED',
        tenant_id: request.tenant_id,
        company_id: request.company_id,
        terminal_id: request.terminal_id,
        operator_id: request.operator_id,
        event_time_device: request.event_time_device,
        business_date: request.business_date,
        payload: buildBreakPayload(request),
      });

      const restartResult = await this.engine.append(txw, {
        event_type: 'CHAIN_RESTART',
        tenant_id: request.tenant_id,
        company_id: request.company_id,
        terminal_id: request.terminal_id,
        operator_id: request.operator_id,
        event_time_device: request.event_time_device,
        business_date: request.business_date,
        payload: buildRestartPayload(request, breakResult),
      });

      // Flip the degraded flag in the SAME transaction as the appends.
      // Scoped per-(tenant, terminal) so a tenant-id collision on
      // terminal_id (defensive — terminal_id is PK) cannot
      // cross-pollute.
      await txw.execute(
        `UPDATE terminal_state
            SET fiscal_chain_status = 'degraded'
          WHERE terminal_id = $1`,
        [request.terminal_id],
      );

      return { break: breakResult, restart: restartResult };
    });
  }
}

/**
 * Validate the caller-supplied `offending_reference`. Round-2 closure
 * for Opus F3 / Codex T25-P3 — the service refuses to manufacture
 * forensic context, so the caller MUST supply a structured non-empty
 * object identifying what triggered the break. Mirrors the PHP-side
 * `FiscalPayloadConstraintValidator::validateNonEmptyAssoc()` semantics
 * (non-null, object, non-empty, not a list).
 */
function assertOffendingReference(value: unknown): void {
  if (value === null || value === undefined) {
    throw new MissingOffendingReferenceError('value was null/undefined');
  }
  if (typeof value !== 'object' || Array.isArray(value)) {
    throw new MissingOffendingReferenceError(
      `value was ${Array.isArray(value) ? 'an array' : typeof value}, expected a non-empty object`,
    );
  }
  if (Object.keys(value as Record<string, unknown>).length === 0) {
    throw new MissingOffendingReferenceError('value was an empty object');
  }
}

/**
 * Build the CHAIN_BREAK_DETECTED payload. Keys mirror the PHP
 * `ChainBreakDetectedPayload` DTO + the `FiscalPayloadConstraintValidator`
 * `PAYLOAD_KEYS['CHAIN_BREAK_DETECTED']` set:
 *
 *   - `reason` (string)
 *   - `last_good_sequence` (int)
 *   - `last_good_hash` (64-char lowercase hex)
 *   - `offending_record_reference` (non-empty assoc; if it carries
 *     `observed_previous_hash`, that's a hash)
 *
 * Round-2: `offending_reference` is asserted non-empty BEFORE this
 * function runs, so the synthetic-placeholder branch is gone. The
 * caller's structured shape lands on the immutable chain as-is.
 */
function buildBreakPayload(request: ChainBreakAndRestartRequest): Record<string, unknown> {
  return {
    reason: request.reason,
    last_good_sequence: request.last_good_sequence,
    last_good_hash: request.last_good_hash,
    offending_record_reference: request.offending_reference,
  };
}

/**
 * Build the CHAIN_RESTART payload. Keys mirror the PHP
 * `ChainRestartPayload` DTO + the `FiscalPayloadConstraintValidator`
 * `PAYLOAD_KEYS['CHAIN_RESTART']` set:
 *
 *   - `new_genesis_reference` (64-char lowercase hex)
 *   - `last_good_anchor` (non-empty assoc; if it carries `hash`, that's a hash)
 *   - `operator_authorization_evidence` (non-empty assoc)
 *   - `provenance_link` (non-empty assoc) → carries
 *     `chain_break_event_id` so the restart is traceable to its break.
 *
 * **`new_genesis_reference`:** spec §9 calls this "the new genesis
 * reference" without prescribing how the device derives it. Phase 1
 * reuses the CHAIN_BREAK_DETECTED's `current_hash` as the genesis
 * reference — the break event itself is the verifiable anchor the
 * restarted chain references. A future phase may upgrade this to a
 * separately-attested anchor (server-issued genesis seed, etc.) once
 * the signature-provider work lands.
 */
function buildRestartPayload(
  request: ChainBreakAndRestartRequest,
  breakResult: FiscalEventAppendResult,
): Record<string, unknown> {
  return {
    new_genesis_reference: breakResult.current_hash,
    last_good_anchor: {
      sequence_number: request.last_good_sequence,
      hash: request.last_good_hash,
    },
    operator_authorization_evidence: request.operator_authorization_evidence,
    provenance_link: {
      chain_break_event_id: breakResult.id,
      chain_break_sequence_number: breakResult.sequence_number,
    },
  };
}
