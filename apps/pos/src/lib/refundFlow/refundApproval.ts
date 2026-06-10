/**
 * Shared manager-PIN approval handshake for refund settlement (Task 2b).
 *
 * EXTRACTED from VoidReturnModal's working `authorizeVoidReturn` + return
 * path (the only previously-working consumer of the void/return override
 * chain) so the refund checkout flow reuses the exact same fiscal authoring
 * instead of copy-pasting it. VoidReturnModal itself is untouched (Phase 6
 * handles its cleanup).
 *
 * Contract pinned by the server's assertVoidReturnApproval: the approval /
 * override fiscal events' `target` must bind the SERVER receipt id + number
 * and the mapped server line_ids (sorted), plus scope / cashier / supervisor /
 * terminal — so this helper MUST run AFTER `prepareRefundSettlement` (the
 * line_ids only exist once mapping succeeded).
 *
 * Sequence (mirrors VoidReturnModal.handleReturn):
 *   1. verifyScopedManagerPin — offline-capable PIN match + authoritative
 *      online confirmation (anti-downgrade policy lives inside).
 *   2. authorPosOverride — appends OPERATOR_APPROVAL_GRANTED +
 *      OVERRIDE_VOID_OR_RETURN to the local fiscal chain in one transaction.
 *   3. ensureApprovalFiscalEventsSynced — the server verifies the evidence
 *      against the synced chain, so both events must land server-side BEFORE
 *      the /return submit.
 */
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';
import {
  authorPosOverride,
  type PosOverrideContext,
} from '@/lib/operatorApproval/posOverrideAuthoring';
import { ensureApprovalFiscalEventsSynced } from '@/lib/operatorApproval/approvalFiscalSync';
import type { RefundApprovalEvidence } from './refundSettlementService';

export interface AuthorizeRefundReturnInput {
  context: PosOverrideContext;
  managerPin: string;
  /** Cashier-entered reason; may be empty (a default reason code is used). */
  reason: string;
  /** SERVER pos_receipts.id resolved by prepareRefundSettlement. */
  serverReceiptId: string;
  receiptNumber: string;
  /** Mapped server line_ids from prepareRefundSettlement (any order). */
  lineIds: string[];
}

/**
 * Verify the manager PIN, author the approval + override fiscal events bound
 * to the server receipt/lines, force-sync them, and return the six-field
 * approval evidence `submitRefundReturn` expects.
 *
 * Throws on PIN mismatch / server denial / authoring or sync failure — the
 * caller surfaces a translated approval error and stays on the PIN step.
 */
export async function authorizeRefundReturnApproval(
  input: AuthorizeRefundReturnInput,
): Promise<RefundApprovalEvidence> {
  const reasonText = input.reason.trim();

  const manager = await verifyScopedManagerPin({
    pin: input.managerPin,
    context: input.context,
    approvalScope: 'void_or_return_override',
    targetEventType: 'POS_RECEIPT_RETURN',
    targetReferenceId: input.serverReceiptId,
    reason: reasonText || 'Void or return override',
  });

  const evidence = await authorPosOverride({
    context: input.context,
    supervisor: {
      id: manager.id,
      name: manager.name,
      roles: manager.roles,
    },
    approvalScope: 'void_or_return_override',
    targetEventType: 'POS_RECEIPT_RETURN',
    targetReferenceId: input.serverReceiptId,
    target: {
      receipt_id: input.serverReceiptId,
      receipt_number: input.receiptNumber,
      line_ids: [...input.lineIds].sort(),
      reason: reasonText,
    },
    policyVersion: 'pos-void-return-policy-v1',
    reasonCode: reasonText === '' ? 'void_or_return_override' : 'manager_reason',
    reasonText: reasonText || null,
  });

  await ensureApprovalFiscalEventsSynced(input.context.companyId, [
    evidence.approval_event_id,
    evidence.override_event_id,
  ]);

  return {
    approval_id: evidence.approval_id,
    approval_fiscal_event_id: evidence.approval_event_id,
    approval_scope: 'void_or_return_override',
    approval_supervisor_user_id: evidence.supervisor_user_id,
    approval_override_event_id: evidence.override_event_id,
    authorized_by_user_id: evidence.supervisor_user_id,
  };
}
