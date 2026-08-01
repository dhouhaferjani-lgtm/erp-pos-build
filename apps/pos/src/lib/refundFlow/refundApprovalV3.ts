/**
 * v3-refund-chain-integration spec §4.2 — the v4 refund flow's approval
 * authoring caller. Sibling to the LEGACY `refundApproval.ts` (which binds
 * to the SERVER-resolved `pos_receipts.id`/mapped server `line_ids` for the
 * legacy `POS_RECEIPT_RETURN` correction path) — this file binds to the
 * DEVICE-LOCAL `original_local_receipt_id`/frozen line snapshot the v4
 * refund's `original_line_references[]` (§3.3) is built from, and threads
 * the intent's PRE-GENERATED `sourceEventIds` through so the append itself
 * is recoverable by a stable, known-in-advance identifier after a crash
 * (unlike the legacy path, which lets `authorPosOverride()` generate its
 * own ids since it has no durable pre-signing intent row to pre-populate
 * from).
 */
import { getDatabase, queryOne } from '@/lib/db';
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';
import {
  authorPosOverride,
  type PosOverrideContext,
  type PosOverrideEvidence,
} from '@/lib/operatorApproval/posOverrideAuthoring';
import { getFiscalEventEngine } from '@/lib/fiscal/instance';
import { withWriteTransaction } from '@/lib/db/writeGate';
import type { LocalFiscalEvent } from '@/lib/db/repositories/fiscalEventRepository';

export interface AuthorRefundReturnApprovalV3Input {
  context: PosOverrideContext;
  managerPin: string;
  /** Cashier-entered reason; may be empty (a default reason code is used). */
  reason: string;
  /** Device-local original receipt identity (spec §4.4's refund_intents.
   *  original_local_receipt_id) — NOT a server-resolved id. */
  originalLocalReceiptId: string;
  originalFiscalEventId: string;
  /** The frozen original_line_references[]/line_items[] pair (§3.1) this
   *  refund attempt covers — becomes the override event's `target`. */
  lineSnapshot: unknown;
  /**
   * Pre-generated at `refund_intents` row creation (§4.2/§4.4), BEFORE
   * the manager PIN is even entered — durable, known in advance,
   * unaffected by restart. The caller reads these from the already-
   * created `refund_intents` row (`approval_source_event_id`/
   * `override_source_event_id`) and threads them straight through.
   */
  approvalSourceEventId: string;
  overrideSourceEventId: string;
  eventTimeDevice?: Date;
}

/**
 * Verifies the manager PIN, then authors the paired approval + override
 * fiscal events bound to the DEVICE-LOCAL original + frozen line
 * selection, using the intent's own pre-generated `sourceEventIds` so the
 * append is idempotent/recoverable by a stable id after a crash. Returns
 * the same seven-field evidence shape `SaleReceiptApprovalReferenceInput`
 * needs (§4.2's projector-side verification reads these exact fields back
 * off the resolved fiscal events).
 *
 * Throws on PIN mismatch / server denial / authoring failure — nothing is
 * appended to the chain unless `authorPosOverride()` committed.
 */
export async function authorRefundReturnApprovalV3(
  input: AuthorRefundReturnApprovalV3Input,
): Promise<PosOverrideEvidence> {
  const reasonText = input.reason.trim();

  const manager = await verifyScopedManagerPin({
    pin: input.managerPin,
    context: input.context,
    approvalScope: 'void_or_return_override',
    targetEventType: 'SALE_RECEIPT',
    targetReferenceId: input.originalLocalReceiptId,
    reason: reasonText || 'Void or return override',
  });

  return authorPosOverride({
    context: input.context,
    supervisor: {
      id: manager.id,
      name: manager.name,
      roles: manager.roles,
    },
    approvalScope: 'void_or_return_override',
    targetEventType: 'SALE_RECEIPT',
    targetReferenceId: input.originalLocalReceiptId,
    target: {
      original_local_receipt_id: input.originalLocalReceiptId,
      original_fiscal_event_id: input.originalFiscalEventId,
      line_snapshot: input.lineSnapshot,
      reason: reasonText,
    },
    policyVersion: 'pos-refund-v4-void-return-policy-v1',
    reasonCode: reasonText === '' ? 'void_or_return_override' : 'manager_reason',
    reasonText: reasonText || null,
    eventTimeDevice: input.eventTimeDevice,
    sourceEventIds: {
      approval: input.approvalSourceEventId,
      override: input.overrideSourceEventId,
    },
  });
}

export interface RecoveredRefundApprovalEvidence {
  approvalEvent: LocalFiscalEvent | null;
  overrideEvent: LocalFiscalEvent | null;
}

/**
 * Exact recovery query (spec §4.2, "closing Codex's recovery is not
 * implementable finding"): resolves BOTH authored events purely from the
 * device's local `fiscal_events` mirror, keyed by the intent's own
 * pre-generated `sourceEventIds` — no network call, resolvable at any
 * point after `refund_intents` row creation regardless of what crashed
 * and when.
 */
export async function resolveRefundApprovalEvidenceLocally(
  companyId: string,
  approvalSourceEventId: string,
  overrideSourceEventId: string,
): Promise<RecoveredRefundApprovalEvidence> {
  const db = await getDatabase(companyId);

  const approvalEvent = await queryOne<LocalFiscalEvent>(
    db,
    `SELECT * FROM fiscal_events
      WHERE source_event_class = 'operator_approval' AND source_event_id = $1`,
    [approvalSourceEventId],
  );
  const overrideEvent = await queryOne<LocalFiscalEvent>(
    db,
    `SELECT * FROM fiscal_events
      WHERE source_event_class = 'pos_override' AND source_event_id = $1`,
    [overrideSourceEventId],
  );

  return { approvalEvent, overrideEvent };
}

/**
 * Wave-2 fix-wave finding 11 (fiscal I-2 + codex M-3) — RESUME, don't
 * re-author.
 *
 * When a v4 refund attempt is abandoned after the manager PIN was already
 * spent (cancel at the approval step, a crash, an app restart), the intent
 * row survives at `approval_authored` and `createOrReuseActiveRefundIntent()`
 * REUSES it. The store's in-memory `approval` cache is gone, though, so the
 * flow used to re-run `authorRefundReturnApprovalV3()` — asking for a
 * second manager PIN for a refund whose approval is already signed and on
 * the chain.
 *
 * This reconstructs the seven-field `PosOverrideEvidence` purely from the
 * device's OWN local `fiscal_events` mirror, keyed by the intent's
 * pre-generated `sourceEventIds`. Fail-closed: a missing event, an
 * unreadable envelope, a missing field, or an approval-id/scope
 * DISAGREEMENT between the two events all return `null`, and the caller
 * treats that as "cannot resume" rather than guessing. (An
 * approval_id disagreement is exactly what finding 6 made structurally
 * impossible going forward, but a row authored by a pre-fix build could
 * still carry one — so it is rejected here rather than propagated into a
 * signed refund that the projector would dead-letter.)
 */
export async function recoverRefundApprovalEvidenceLocally(
  companyId: string,
  approvalSourceEventId: string,
  overrideSourceEventId: string,
  targetReferenceId: string,
): Promise<PosOverrideEvidence | null> {
  const { approvalEvent, overrideEvent } = await resolveRefundApprovalEvidenceLocally(
    companyId,
    approvalSourceEventId,
    overrideSourceEventId,
  );
  if (approvalEvent === null || overrideEvent === null) return null;

  const approvalPayload = readSignedPayload(approvalEvent.canonical_bytes);
  const overridePayload = readSignedPayload(overrideEvent.canonical_bytes);
  if (approvalPayload === null || overridePayload === null) return null;

  const approvalId = readNonEmptyString(approvalPayload, 'approval_id');
  const policyVersion = readNonEmptyString(approvalPayload, 'policy_version');
  const supervisorUserId = readNonEmptyString(approvalPayload, 'supervisor_user_id');
  const approvalScope = readNonEmptyString(approvalPayload, 'approval_scope');
  if (
    approvalId === null
    || policyVersion === null
    || supervisorUserId === null
    || approvalScope !== 'void_or_return_override'
  ) {
    return null;
  }

  // The projector cross-checks these across BOTH events; if the local
  // mirror already disagrees, resuming would author a refund guaranteed to
  // dead-letter server-side.
  if (
    readNonEmptyString(overridePayload, 'approval_id') !== approvalId
    || readNonEmptyString(overridePayload, 'approval_scope') !== approvalScope
    || readNonEmptyString(overridePayload, 'policy_version') !== policyVersion
    || readNonEmptyString(overridePayload, 'supervisor_user_id') !== supervisorUserId
    || readNonEmptyString(overridePayload, 'approval_event_id') !== approvalEvent.id
  ) {
    return null;
  }

  return {
    approval_id: approvalId,
    approval_event_id: approvalEvent.id,
    approval_scope: 'void_or_return_override',
    override_event_id: overrideEvent.id,
    policy_version: policyVersion,
    supervisor_user_id: supervisorUserId,
    target_reference_id: targetReferenceId,
  };
}

/** `canonical_bytes` is the chain ENVELOPE; the signed payload is nested
 *  one level down at `envelope.payload`. */
function readSignedPayload(canonicalBytes: string): Record<string, unknown> | null {
  try {
    const envelope: unknown = JSON.parse(canonicalBytes);
    if (typeof envelope !== 'object' || envelope === null || Array.isArray(envelope)) return null;
    const payload = (envelope as Record<string, unknown>)['payload'];
    if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) return null;
    return payload as Record<string, unknown>;
  } catch {
    return null;
  }
}

function readNonEmptyString(payload: Record<string, unknown>, key: string): string | null {
  const value = payload[key];
  return typeof value === 'string' && value !== '' ? value : null;
}

function isoSecondsUtc(date: Date): string {
  return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
}

export interface AuthorPayoutDisputeEvidenceInput {
  context: PosOverrideContext;
  /**
   * The disputing operator's own identity — NOT a supervisor/manager. This
   * is a solo evidence record (spec §4.5), authored by the cashier who
   * could not confirm the cash left the drawer; there is no elevated
   * authority to verify and no paired override event (unlike
   * `authorRefundReturnApprovalV3()` above), so no PIN is required.
   */
  operator: { id: string; name: string; roles?: string[] };
  /** The already-signed v4 REFUND fiscal event this dispute concerns. */
  refundFiscalEventId: string;
  /** The `refund_intents` row this dispute concerns — doubles as the
   *  device-local idempotency key so a double-tap of "dispute" cannot
   *  author two evidence events for the same refund. */
  refundIntentId: string;
  /** Cashier-entered note; may be empty. */
  reason: string;
  eventTimeDevice?: Date;
}

export interface PayoutDisputeEvidenceResult {
  approval_id: string;
  approval_event_id: string;
}

/**
 * v3-refund-chain-integration spec §4.5 — authors the SOLO
 * `OPERATOR_APPROVAL_GRANTED` audit event that records "the local device
 * could not confirm this cash left the drawer" for the finance team's own
 * investigation. Deliberately does NOT change the already-signed refund
 * fiscal event's effect and does NOT touch `refund_intents` state or the
 * device Z's arithmetic (spec §7.3's sign convention) — purely a
 * server-observable evidence signal, entirely separate from and
 * non-blocking of fiscal reality.
 *
 * This is the ONLY legitimate author of `approval_scope:
 * 'payout_dispute_evidence'` — `authorPosOverride()` (the PAIRED
 * approval+override function) hard-rejects that scope before appending
 * anything, since there is no paired `OVERRIDE_*` event for it (§4.5
 * errata T6). Reuses `OPERATOR_APPROVAL_GRANTED`'s existing payload shape
 * rather than authoring a sixth `OVERRIDE_*` event type.
 */
export async function authorPayoutDisputeEvidence(
  input: AuthorPayoutDisputeEvidenceInput,
): Promise<PayoutDisputeEvidenceResult> {
  const reasonText = input.reason.trim();
  const eventTimeDevice = input.eventTimeDevice ?? new Date();
  const approvalId = crypto.randomUUID();
  const db = await getDatabase(input.context.companyId);
  const engine = await getFiscalEventEngine(input.context.companyId, db);

  return withWriteTransaction('fiscal', async (tx) => {
    const approvalEvent = await engine.append(tx, {
      event_type: 'OPERATOR_APPROVAL_GRANTED',
      tenant_id: input.context.tenantId,
      company_id: input.context.companyId,
      terminal_id: input.context.terminalId,
      operator_id: input.operator.id,
      event_time_device: isoSecondsUtc(eventTimeDevice),
      business_date: input.context.businessDate,
      payload: {
        approval_id: approvalId,
        approval_scope: 'payout_dispute_evidence',
        cashier_user_id: input.context.cashierUserId,
        company_id: input.context.companyId,
        event_time_device: eventTimeDevice.toISOString(),
        policy_version: 'pos-refund-v4-payout-dispute-evidence-v1',
        reason_code: reasonText === '' ? 'payout_dispute' : 'payout_dispute_reason',
        reason_text: reasonText || null,
        regime_extensions: null,
        requested_at_device: eventTimeDevice.toISOString(),
        resolved_at_device: eventTimeDevice.toISOString(),
        // No supervisor for a SOLO evidence event — the disputing operator
        // fills this slot; there is no distinct elevated-authority
        // approver to record separately from the author.
        supervisor_user_id: input.operator.id,
        supervisor_user_snapshot: {
          name: input.operator.name,
          roles: input.operator.roles ?? [],
        },
        target: {
          refund_fiscal_event_id: input.refundFiscalEventId,
          refund_intent_id: input.refundIntentId,
          reason: reasonText,
        },
        tenant_id: input.context.tenantId,
        terminal_id: input.context.terminalId,
        training_flag: input.context.isTraining,
      },
      source_event_class: 'payout_dispute_evidence',
      // Keyed by the refund_intents row id — idempotent by construction:
      // a repeat dispute tap for the same refund resolves to the SAME
      // append rather than authoring a second evidence event.
      source_event_id: input.refundIntentId,
    });

    return {
      approval_id: approvalId,
      approval_event_id: approvalEvent.id,
    };
  });
}
