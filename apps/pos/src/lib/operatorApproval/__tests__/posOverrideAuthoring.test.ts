import { beforeEach, describe, expect, it, vi } from 'vitest';

const { appendMock } = vi.hoisted(() => ({ appendMock: vi.fn() }));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

vi.mock('@/lib/db/writeGate', () => ({
  withWriteTransaction: vi.fn((_lane: string, cb: (tx: unknown) => unknown) => cb({})),
}));

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn().mockResolvedValue({ append: appendMock }),
}));

import {
  authorPosOverride,
  type AuthorPosOverrideInput,
  type PosOverrideContext,
  type PosOverrideSupervisor,
} from '../posOverrideAuthoring';

/**
 * v3-refund-chain-integration spec §4.2/§4.5 errata T6 — `authorPosOverride()`'s
 * new coverage: the caller-supplied `sourceEventIds` overload (backward-
 * compatible: existing callers omitting it keep today's exact internally-
 * generated behavior) and the `payout_dispute_evidence` scope's explicit
 * rejection (it has no paired override event -- reuses
 * `OPERATOR_APPROVAL_GRANTED`'s shape rather than authoring a sixth
 * `OVERRIDE_*` type, so this PAIRED authoring function must never accept
 * it).
 */

const CONTEXT: PosOverrideContext = {
  tenantId: 'tenant-1',
  companyId: 'company-1',
  terminalId: 'terminal-1',
  cashierUserId: 'cashier-1',
  businessDate: '2026-05-20',
  isTraining: false,
};

const SUPERVISOR: PosOverrideSupervisor = {
  id: 'supervisor-1',
  name: 'Manager Alice',
  roles: ['manager'],
};

function baseInput(overrides: Partial<AuthorPosOverrideInput> = {}): AuthorPosOverrideInput {
  return {
    context: CONTEXT,
    supervisor: SUPERVISOR,
    approvalScope: 'void_or_return_override',
    targetEventType: 'SALE_RECEIPT',
    targetReferenceId: 'receipt-1',
    target: { some: 'target' },
    policyVersion: 'v1',
    reasonCode: 'customer_request',
    reasonText: null,
    eventTimeDevice: new Date('2026-05-20T10:00:00.000Z'),
    ...overrides,
  };
}

describe('authorPosOverride', () => {
  beforeEach(() => {
    appendMock.mockReset();
    let seq = 0;
    appendMock.mockImplementation((_tx: unknown, request: { event_type: string; source_event_id?: string }) => {
      seq += 1;
      return Promise.resolve({
        id: `${request.event_type}-fiscal-event-${String(seq)}`,
        event_type: request.event_type,
        sequence_number: seq,
        current_hash: 'h'.repeat(64),
        previous_hash: 'p'.repeat(64),
        event_version: 1,
        canonical_bytes: '{}',
      });
    });
  });

  it('generates source_event_ids internally when sourceEventIds is omitted (existing callers, byte-identical behavior)', async () => {
    await authorPosOverride(baseInput());

    expect(appendMock).toHaveBeenCalledTimes(2);
    const [, approvalCall] = appendMock.mock.calls[0] as [unknown, { source_event_class: string; source_event_id: string }];
    const [, overrideCall] = appendMock.mock.calls[1] as [unknown, { source_event_class: string; source_event_id: string }];

    expect(approvalCall.source_event_class).toBe('operator_approval');
    // Internally generated -- a UUID, not the composite override shape.
    expect(approvalCall.source_event_id).toMatch(/^[0-9a-f-]{36}$/);

    expect(overrideCall.source_event_class).toBe('pos_override');
    expect(overrideCall.source_event_id).toBe('receipt-1:void_or_return_override');
  });

  it('uses the caller-supplied sourceEventIds when provided (spec §4.2)', async () => {
    await authorPosOverride(
      baseInput({
        sourceEventIds: { approval: 'approval-uuid-123', override: 'override-uuid-456' },
      }),
    );

    const [, approvalCall] = appendMock.mock.calls[0] as [unknown, { source_event_id: string }];
    const [, overrideCall] = appendMock.mock.calls[1] as [unknown, { source_event_id: string }];

    expect(approvalCall.source_event_id).toBe('approval-uuid-123');
    expect(overrideCall.source_event_id).toBe('override-uuid-456');
  });

  it('the payload approval_id field is unaffected by sourceEventIds (still the internally-generated UUID)', async () => {
    await authorPosOverride(
      baseInput({ sourceEventIds: { approval: 'approval-uuid-123', override: 'override-uuid-456' } }),
    );

    const [, approvalCall] = appendMock.mock.calls[0] as [unknown, { payload: { approval_id: string } }];
    expect(approvalCall.payload.approval_id).not.toBe('approval-uuid-123');
    expect(approvalCall.payload.approval_id).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('dispatches the correct OVERRIDE_* event type per approval scope', async () => {
    await authorPosOverride(baseInput({ approvalScope: 'discount_limit_override' }));
    expect((appendMock.mock.calls[1]?.[1] as { event_type: string }).event_type).toBe('OVERRIDE_DISCOUNT_LIMIT');

    appendMock.mockClear();
    await authorPosOverride(baseInput({ approvalScope: 'tender_tolerance_override' }));
    expect((appendMock.mock.calls[1]?.[1] as { event_type: string }).event_type).toBe('OVERRIDE_TENDER_TOLERANCE');

    appendMock.mockClear();
    await authorPosOverride(baseInput({ approvalScope: 'void_or_return_override' }));
    expect((appendMock.mock.calls[1]?.[1] as { event_type: string }).event_type).toBe('OVERRIDE_VOID_OR_RETURN');
  });

  it('spec §4.5 errata T6 — rejects payout_dispute_evidence: this PAIRED function has no override event for it', async () => {
    await expect(
      authorPosOverride(baseInput({ approvalScope: 'payout_dispute_evidence' })),
    ).rejects.toThrow(/Unsupported approval scope: payout_dispute_evidence/);

    // Fail-closed BEFORE any state mutation -- neither event is appended.
    expect(appendMock).not.toHaveBeenCalled();
  });

  it('returns evidence carrying both fiscal event ids and the approval scope', async () => {
    const evidence = await authorPosOverride(baseInput());

    expect(evidence.approval_event_id).toMatch(/^OPERATOR_APPROVAL_GRANTED-fiscal-event-1$/);
    expect(evidence.override_event_id).toMatch(/^OVERRIDE_VOID_OR_RETURN-fiscal-event-2$/);
    expect(evidence.approval_scope).toBe('void_or_return_override');
    expect(evidence.target_reference_id).toBe('receipt-1');
  });
});
