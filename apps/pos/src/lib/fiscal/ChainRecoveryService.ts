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
 *   1. `CHAIN_BREAK_DETECTED` — `{ reason, last_good_sequence_number,
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
 * The service is intentionally thin — it does NOT decide *when* to call
 * (callers detect the break via §15 verifier output or local
 * structural checks) and it does NOT manage the `degraded` flag on
 * `terminal_state` (that flag is owned by the caller transaction, like
 * the receipt-assembler owns its `offline_receipts` row write). What
 * it owns: deterministic emission order, payload shape compliance,
 * and provenance linkage from CHAIN_RESTART back to the
 * CHAIN_BREAK_DETECTED it resolves.
 */

import type Database from '@tauri-apps/plugin-sql';

import {
  FiscalEventEngine,
  type FiscalEventAppendResult,
  type SqlSurface,
} from './FiscalEventEngine';

/**
 * Caller-supplied input describing the break + the restart authorization.
 *
 * `last_good_sequence` / `last_good_hash` identify the last verifiable
 * anchor on the chain. `offending_reference` is the (optional) identifier
 * of the row that triggered the break — typically the event UUID or the
 * synthetic key the verifier surfaced. `operator_authorization_evidence`
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
  /** Optional identifier of the offending row (UUID, synthetic key, etc.). */
  offending_reference?: string;
  /** `{ user_id, role, reason_code, manager_pin_attestation, ... }`. */
  operator_authorization_evidence: Record<string, unknown>;
}

/**
 * Result of a successful chain-recovery emission. Both events are
 * returned so the caller can update `terminal_state.degraded` /
 * forensic logs / sync metadata against the canonical event ids.
 */
export interface ChainRecoveryResult {
  break: FiscalEventAppendResult;
  restart: FiscalEventAppendResult;
}

export class ChainRecoveryService {
  constructor(private readonly engine: FiscalEventEngine) {}

  /**
   * Author CHAIN_BREAK_DETECTED then CHAIN_RESTART against the SAME
   * `(tenant, terminal)` chain. The two events are emitted via
   * `FiscalEventEngine.append()` so they participate in the usual chain
   * head advance, hash linkage, and idempotency mechanics.
   *
   * **Transactional posture.** Mirrors `FiscalEventEngine.append()` —
   * runs inside the caller's transaction. If the caller wraps the call
   * in `BEGIN`/`COMMIT`, both events land atomically (or both roll back).
   * If the caller does NOT wrap, the underlying SQLite engine treats
   * each `engine.append()` as its own implicit transaction; the second
   * append still chains correctly off the first because
   * `engine.append()` re-reads the chain head per call.
   *
   * **Provenance link.** CHAIN_RESTART's `provenance_link.chain_break_event_id`
   * is set to the UUID of the CHAIN_BREAK_DETECTED event we just
   * appended — guarantees the restart is forensically traceable to its
   * own break, not a sibling chain break.
   */
  async recordBreakAndRestart(
    tx: Database | SqlSurface,
    request: ChainBreakAndRestartRequest,
  ): Promise<ChainRecoveryResult> {
    const breakResult = await this.engine.append(tx, {
      event_type: 'CHAIN_BREAK_DETECTED',
      tenant_id: request.tenant_id,
      company_id: request.company_id,
      terminal_id: request.terminal_id,
      operator_id: request.operator_id,
      event_time_device: request.event_time_device,
      business_date: request.business_date,
      payload: buildBreakPayload(request),
    });

    const restartResult = await this.engine.append(tx, {
      event_type: 'CHAIN_RESTART',
      tenant_id: request.tenant_id,
      company_id: request.company_id,
      terminal_id: request.terminal_id,
      operator_id: request.operator_id,
      event_time_device: request.event_time_device,
      business_date: request.business_date,
      payload: buildRestartPayload(request, breakResult),
    });

    return { break: breakResult, restart: restartResult };
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
 * `offending_record_reference` is always emitted as a non-empty object —
 * the validator rejects an empty assoc. When the caller cannot identify
 * the offending row, we record that explicitly via a `kind` discriminator
 * so the forensic export distinguishes "unknown offender" from "offender
 * id was X".
 */
function buildBreakPayload(request: ChainBreakAndRestartRequest): Record<string, unknown> {
  const offending: Record<string, unknown> = request.offending_reference
    ? {
        offending_reference: request.offending_reference,
        terminal_id: request.terminal_id,
      }
    : {
        kind: 'unknown_offender',
        terminal_id: request.terminal_id,
      };

  return {
    reason: request.reason,
    last_good_sequence: request.last_good_sequence,
    last_good_hash: request.last_good_hash,
    offending_record_reference: offending,
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
