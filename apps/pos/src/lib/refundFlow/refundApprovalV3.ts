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
