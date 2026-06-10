/**
 * Shared manager-PIN approval handshake for refund settlement (Task 2b).
 *
 * EXTRACTED from the former VoidReturnModal's working `authorizeVoidReturn`
 * + return path (the only previously-working consumer of the void/return
 * override chain) so the refund checkout flow reuses the exact same fiscal
 * authoring instead of copy-pasting it. VoidReturnModal was deleted in
 * Phase 6 — the refund flow is the only post-seal correction surface on the
 * POS, and this helper is the canonical owner of the handshake.
 *
 * Contract pinned by the server's assertVoidReturnApproval: the approval /
 * override fiscal events' `target` must bind the SERVER receipt id + number
 * and the mapped server line_ids (sorted), plus scope / cashier / supervisor /
 * terminal — so `authorRefundReturnApproval` MUST run AFTER
 * `prepareRefundSettlement` (the line_ids only exist once mapping succeeded).
 *
 * SPLIT INTO TWO PHASES (review Fix 1 — fiscal):
 *
 *   1. `authorRefundReturnApproval` — verifyScopedManagerPin (offline-capable
 *      PIN match + authoritative online confirmation, anti-downgrade policy
 *      inside) then authorPosOverride (appends OPERATOR_APPROVAL_GRANTED +
 *      OVERRIDE_VOID_OR_RETURN to the local fiscal chain in one transaction).
 *      Runs AT MOST ONCE per settlement attempt — the caller MUST cache the
 *      returned evidence immediately.
 *   2. `syncRefundApprovalEvents` — forces both authored events to land
 *      server-side BEFORE the /return submit (the server verifies the
 *      evidence against the synced chain). Idempotent / re-runnable: a sync
 *      failure must NOT re-author — the caller retries THIS step only, with
 *      the cached evidence, so the signed chain never accumulates duplicate
 *      approval+override pairs for the same target.
 */
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';
import {
  authorPosOverride,
  type PosOverrideContext,
} from '@/lib/operatorApproval/posOverrideAuthoring';
import { ensureApprovalFiscalEventsSynced } from '@/lib/operatorApproval/approvalFiscalSync';
import type { RefundApprovalEvidence } from './refundSettlementService';

export interface AuthorRefundReturnApprovalInput {
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
 * Phase 1 — verify the manager PIN and author the approval + override fiscal
 * events bound to the server receipt/lines. Returns the six-field approval
 * evidence `submitRefundReturn` expects. Does NOT sync — the caller caches
 * the evidence first, then runs `syncRefundApprovalEvents`, so a sync failure
 * can never discard authored evidence.
 *
 * Throws on PIN mismatch / server denial / authoring failure — nothing was
 * appended to the chain unless authorPosOverride committed, so the caller may
 * surface a PIN error and let the cashier try again.
 */
export async function authorRefundReturnApproval(
  input: AuthorRefundReturnApprovalInput,
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

  return {
    approval_id: evidence.approval_id,
    approval_fiscal_event_id: evidence.approval_event_id,
    approval_scope: 'void_or_return_override',
    approval_supervisor_user_id: evidence.supervisor_user_id,
    approval_override_event_id: evidence.override_event_id,
    authorized_by_user_id: evidence.supervisor_user_id,
  };
}

/**
 * Phase 2 — force the authored approval + override fiscal events to sync so
 * the server can verify the evidence on the /return submit. Re-runnable: on
 * failure the caller retries THIS function with the SAME cached evidence
 * (no second PIN entry, no re-authoring).
 */
export async function syncRefundApprovalEvents(
  companyId: string,
  evidence: RefundApprovalEvidence,
): Promise<void> {
  await ensureApprovalFiscalEventsSynced(companyId, [
    evidence.approval_fiscal_event_id,
    evidence.approval_override_event_id,
  ]);
}
