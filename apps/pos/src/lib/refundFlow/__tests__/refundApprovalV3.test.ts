/**
 * v3-refund-chain-integration spec §4.2 — the v4 refund flow's approval
 * authoring caller. The handshake itself (verifyScopedManagerPin /
 * authorPosOverride) is mocked; these tests pin the BINDING contract:
 * the target binds the DEVICE-LOCAL original + frozen line snapshot (not
 * a server-resolved receipt/line id, unlike the legacy path), and the
 * intent's PRE-GENERATED sourceEventIds are threaded straight through to
 * `authorPosOverride()`.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
  queryOne: vi.fn(),
}));
vi.mock('@/lib/operatorApproval/scopedManagerPin', () => ({
  verifyScopedManagerPin: vi.fn(),
}));
vi.mock('@/lib/operatorApproval/posOverrideAuthoring', () => ({
  authorPosOverride: vi.fn(),
}));

import { queryOne } from '@/lib/db';
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';
import { authorPosOverride } from '@/lib/operatorApproval/posOverrideAuthoring';
import type { PosOverrideContext } from '@/lib/operatorApproval/posOverrideAuthoring';
import {
  authorRefundReturnApprovalV3,
  resolveRefundApprovalEvidenceLocally,
} from '../refundApprovalV3';

const context: PosOverrideContext = {
  tenantId: 'tenant-1',
  companyId: 'company-1',
  terminalId: 'terminal-1',
  cashierUserId: 'cashier-1',
  businessDate: '2026-05-20',
  isTraining: false,
};

const ORIGINAL_LOCAL_RECEIPT_ID = '00000000-0000-4000-8000-000000000001';
const ORIGINAL_FISCAL_EVENT_ID = '99999999-9999-4999-8999-999999999999';
const LINE_SNAPSHOT = [{ product_id: 'prod-1', quantity: '1.000', disposition: 'restock' }];

function baseInput() {
  return {
    context,
    managerPin: '4321',
    reason: '  customer return  ',
    originalLocalReceiptId: ORIGINAL_LOCAL_RECEIPT_ID,
    originalFiscalEventId: ORIGINAL_FISCAL_EVENT_ID,
    lineSnapshot: LINE_SNAPSHOT,
    approvalSourceEventId: 'pre-generated-approval-uuid',
    overrideSourceEventId: 'pre-generated-override-uuid',
  };
}

const manager = { id: 'manager-9', name: 'Manager Nine', roles: ['manager'] };

const evidence = {
  approval_id: 'approval-uuid',
  approval_event_id: 'fe-approval-1',
  approval_scope: 'void_or_return_override' as const,
  override_event_id: 'fe-override-1',
  policy_version: 'pos-refund-v4-void-return-policy-v1',
  supervisor_user_id: 'manager-9',
  target_reference_id: ORIGINAL_LOCAL_RECEIPT_ID,
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(verifyScopedManagerPin).mockResolvedValue(manager);
  vi.mocked(authorPosOverride).mockResolvedValue(evidence);
});

describe('authorRefundReturnApprovalV3', () => {
  it('verifies the manager PIN with void_or_return_override bound to the DEVICE-LOCAL original receipt id', async () => {
    await authorRefundReturnApprovalV3(baseInput());

    expect(verifyScopedManagerPin).toHaveBeenCalledWith({
      pin: '4321',
      context,
      approvalScope: 'void_or_return_override',
      targetEventType: 'SALE_RECEIPT',
      targetReferenceId: ORIGINAL_LOCAL_RECEIPT_ID,
      reason: 'customer return',
    });
  });

  it('authors the approval + override events with the target binding the original + frozen line snapshot, and threads the pre-generated sourceEventIds through', async () => {
    await authorRefundReturnApprovalV3(baseInput());

    expect(authorPosOverride).toHaveBeenCalledWith({
      context,
      supervisor: { id: 'manager-9', name: 'Manager Nine', roles: ['manager'] },
      approvalScope: 'void_or_return_override',
      targetEventType: 'SALE_RECEIPT',
      targetReferenceId: ORIGINAL_LOCAL_RECEIPT_ID,
      target: {
        original_local_receipt_id: ORIGINAL_LOCAL_RECEIPT_ID,
        original_fiscal_event_id: ORIGINAL_FISCAL_EVENT_ID,
        line_snapshot: LINE_SNAPSHOT,
        reason: 'customer return',
      },
      policyVersion: 'pos-refund-v4-void-return-policy-v1',
      reasonCode: 'manager_reason',
      reasonText: 'customer return',
      eventTimeDevice: undefined,
      sourceEventIds: {
        approval: 'pre-generated-approval-uuid',
        override: 'pre-generated-override-uuid',
      },
    });
  });

  it('falls back to the default reason code when the cashier left the reason blank', async () => {
    await authorRefundReturnApprovalV3({ ...baseInput(), reason: '   ' });

    expect(authorPosOverride).toHaveBeenCalledWith(
      expect.objectContaining({ reasonCode: 'void_or_return_override', reasonText: null }),
    );
  });

  it('returns the seven-field evidence authorPosOverride produced, unmodified', async () => {
    const result = await authorRefundReturnApprovalV3(baseInput());
    expect(result).toEqual(evidence);
  });

  it('propagates a PIN verification failure without calling authorPosOverride', async () => {
    vi.mocked(verifyScopedManagerPin).mockRejectedValue(new Error('manager_pin_scope_mismatch'));

    await expect(authorRefundReturnApprovalV3(baseInput())).rejects.toThrow('manager_pin_scope_mismatch');
    expect(authorPosOverride).not.toHaveBeenCalled();
  });
});

describe('resolveRefundApprovalEvidenceLocally', () => {
  it('resolves both events by their pre-generated sourceEventIds via the exact recovery query (spec §4.2)', async () => {
    const approvalEvent = { id: 'fe-approval-1', event_type: 'OPERATOR_APPROVAL_GRANTED' };
    const overrideEvent = { id: 'fe-override-1', event_type: 'OVERRIDE_VOID_OR_RETURN' };
    vi.mocked(queryOne)
      .mockResolvedValueOnce(approvalEvent)
      .mockResolvedValueOnce(overrideEvent);

    const result = await resolveRefundApprovalEvidenceLocally(
      'company-1',
      'pre-generated-approval-uuid',
      'pre-generated-override-uuid',
    );

    expect(result).toEqual({ approvalEvent, overrideEvent });
    expect(queryOne).toHaveBeenNthCalledWith(
      1,
      {},
      expect.stringMatching(/source_event_class = 'operator_approval'/),
      ['pre-generated-approval-uuid'],
    );
    expect(queryOne).toHaveBeenNthCalledWith(
      2,
      {},
      expect.stringMatching(/source_event_class = 'pos_override'/),
      ['pre-generated-override-uuid'],
    );
  });

  it('resolves to null for either event when not yet found locally (e.g. mid-crash-recovery window)', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(null).mockResolvedValueOnce(null);

    const result = await resolveRefundApprovalEvidenceLocally('company-1', 'approval-id', 'override-id');

    expect(result).toEqual({ approvalEvent: null, overrideEvent: null });
  });
});
