/**
 * Tests for the shared refund-approval handshake (extracted from
 * VoidReturnModal's working manager-PIN flow). The handshake itself
 * (verifyScopedManagerPin / authorPosOverride / ensureApprovalFiscalEventsSynced)
 * is mocked — these tests pin the BINDING contract: the approval target must
 * carry the SERVER receipt id/number and the mapped line_ids (sorted), because
 * the server's assertVoidReturnApproval verifies exactly that.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { verifyScopedManagerPin } from '@/lib/operatorApproval/scopedManagerPin';
import { authorPosOverride } from '@/lib/operatorApproval/posOverrideAuthoring';
import type { PosOverrideContext } from '@/lib/operatorApproval/posOverrideAuthoring';
import { ensureApprovalFiscalEventsSynced } from '@/lib/operatorApproval/approvalFiscalSync';
import { authorizeRefundReturnApproval } from '../refundApproval';

vi.mock('@/lib/operatorApproval/scopedManagerPin', () => ({
  verifyScopedManagerPin: vi.fn(),
}));
vi.mock('@/lib/operatorApproval/posOverrideAuthoring', () => ({
  authorPosOverride: vi.fn(),
}));
vi.mock('@/lib/operatorApproval/approvalFiscalSync', () => ({
  ensureApprovalFiscalEventsSynced: vi.fn(),
}));

const context: PosOverrideContext = {
  tenantId: 'tenant-1',
  companyId: 'company-1',
  terminalId: 'terminal-1',
  cashierUserId: 'cashier-1',
  businessDate: '2026-06-10',
  isTraining: false,
};

const SERVER_RECEIPT_ID = '7d4e2a10-1111-4222-8333-444455556666';

function baseInput() {
  return {
    context,
    managerPin: '4321',
    reason: '  defective item  ',
    serverReceiptId: SERVER_RECEIPT_ID,
    receiptNumber: 'L01-T01-00042',
    lineIds: ['line-b', 'line-a'],
  };
}

const manager = { id: 'manager-9', name: 'Manager Nine', roles: ['manager'] };

const evidence = {
  approval_id: 'approval-uuid',
  approval_event_id: 'fe-approval-1',
  approval_scope: 'void_or_return_override' as const,
  override_event_id: 'fe-override-1',
  policy_version: 'pos-void-return-policy-v1',
  supervisor_user_id: 'manager-9',
  target_reference_id: SERVER_RECEIPT_ID,
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(verifyScopedManagerPin).mockResolvedValue(manager);
  vi.mocked(authorPosOverride).mockResolvedValue(evidence);
  vi.mocked(ensureApprovalFiscalEventsSynced).mockResolvedValue(undefined);
});

describe('authorizeRefundReturnApproval', () => {
  it('verifies the manager PIN with the void_or_return_override scope bound to the server receipt', async () => {
    await authorizeRefundReturnApproval(baseInput());

    expect(verifyScopedManagerPin).toHaveBeenCalledWith({
      pin: '4321',
      context,
      approvalScope: 'void_or_return_override',
      targetEventType: 'POS_RECEIPT_RETURN',
      targetReferenceId: SERVER_RECEIPT_ID,
      reason: 'defective item',
    });
  });

  it('authors the approval + override events with the target binding the server receipt id/number and SORTED line_ids', async () => {
    await authorizeRefundReturnApproval(baseInput());

    expect(authorPosOverride).toHaveBeenCalledWith({
      context,
      supervisor: { id: 'manager-9', name: 'Manager Nine', roles: ['manager'] },
      approvalScope: 'void_or_return_override',
      targetEventType: 'POS_RECEIPT_RETURN',
      targetReferenceId: SERVER_RECEIPT_ID,
      target: {
        receipt_id: SERVER_RECEIPT_ID,
        receipt_number: 'L01-T01-00042',
        line_ids: ['line-a', 'line-b'],
        reason: 'defective item',
      },
      policyVersion: 'pos-void-return-policy-v1',
      reasonCode: 'manager_reason',
      reasonText: 'defective item',
    });
  });

  it('does not mutate the caller line_ids array when sorting', async () => {
    const input = baseInput();
    await authorizeRefundReturnApproval(input);
    expect(input.lineIds).toEqual(['line-b', 'line-a']);
  });

  it('uses the default reason code when the reason is empty', async () => {
    await authorizeRefundReturnApproval({ ...baseInput(), reason: '   ' });

    expect(verifyScopedManagerPin).toHaveBeenCalledWith(
      expect.objectContaining({ reason: 'Void or return override' }),
    );
    expect(authorPosOverride).toHaveBeenCalledWith(
      expect.objectContaining({
        reasonCode: 'void_or_return_override',
        reasonText: null,
        target: expect.objectContaining({ reason: '' }) as Record<string, unknown>,
      }),
    );
  });

  it('forces the authored approval + override fiscal events to sync before returning', async () => {
    await authorizeRefundReturnApproval(baseInput());

    expect(ensureApprovalFiscalEventsSynced).toHaveBeenCalledWith('company-1', [
      'fe-approval-1',
      'fe-override-1',
    ]);
  });

  it('maps the authored evidence onto the six-field approval shape (authorized_by = supervisor)', async () => {
    const result = await authorizeRefundReturnApproval(baseInput());

    expect(result).toEqual({
      approval_id: 'approval-uuid',
      approval_fiscal_event_id: 'fe-approval-1',
      approval_scope: 'void_or_return_override',
      approval_supervisor_user_id: 'manager-9',
      approval_override_event_id: 'fe-override-1',
      authorized_by_user_id: 'manager-9',
    });
  });

  it('propagates a PIN verification failure WITHOUT authoring any fiscal event', async () => {
    vi.mocked(verifyScopedManagerPin).mockRejectedValue(new Error('manager_pin_scope_mismatch'));

    await expect(authorizeRefundReturnApproval(baseInput())).rejects.toThrow(
      'manager_pin_scope_mismatch',
    );
    expect(authorPosOverride).not.toHaveBeenCalled();
    expect(ensureApprovalFiscalEventsSynced).not.toHaveBeenCalled();
  });

  it('propagates a fiscal-sync failure after authoring', async () => {
    vi.mocked(ensureApprovalFiscalEventsSynced).mockRejectedValue(
      new Error('Approval fiscal event is not synced yet.'),
    );

    await expect(authorizeRefundReturnApproval(baseInput())).rejects.toThrow(
      'Approval fiscal event is not synced yet.',
    );
  });
});
