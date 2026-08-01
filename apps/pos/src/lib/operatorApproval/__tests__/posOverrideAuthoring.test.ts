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
  ApprovalIdentityUnreadableError,
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
    appendMock.mockImplementation((_tx: unknown, request: { event_type: string; source_event_id?: string; payload: unknown }) => {
      seq += 1;
      return Promise.resolve({
        id: `${request.event_type}-fiscal-event-${String(seq)}`,
        event_type: request.event_type,
        sequence_number: seq,
        current_hash: 'h'.repeat(64),
        previous_hash: 'p'.repeat(64),
        event_version: 1,
        // Models the REAL engine: `canonical_bytes` is the chain ENVELOPE
        // with the signed payload nested at `envelope.payload`. Round-2
        // fix for finding 6 reads the authoritative `approval_id` back out
        // of exactly this, so a `'{}'` stub would no longer be faithful.
        canonical_bytes: JSON.stringify({ payload: request.payload }),
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

/**
 * Wave-2 fix-wave finding 6 (fiscal C-3) — `approval_id` must be STABLE
 * across a re-authored approval.
 *
 * `authorPosOverride()` minted a FRESH `approval_id` on every call while
 * threading the caller's STABLE `sourceEventIds` into `engine.append()`.
 * The engine's source-based idempotency therefore returned the EXISTING
 * approval/override events, but the returned `PosOverrideEvidence` carried
 * the NEW random `approval_id`. The refund was then signed with an
 * `approval_references[0].approval_id` that matches NEITHER resolved
 * event's own payload, so `PosCoreReceiptProjection`'s cross-check throws
 * `ApprovalEvidenceUnresolvedException` — a NON-RETRYABLE dead-letter,
 * AFTER the cash left the drawer.
 *
 * Reachable by an ordinary cashier action: begin a v4 refund, enter the
 * manager PIN (approval+override signed against the intent's pre-generated
 * ids), press Cancel, then press Pay again on the same return cart — the
 * intent row is REUSED, so the same `sourceEventIds` are threaded again.
 */
describe('authorPosOverride — approval_id stability across re-authoring (finding 6)', () => {
  /**
   * Models `FiscalEventEngine.append()` faithfully: the FIRST call for a
   * given `source_event_id` stores and returns a new event whose
   * `canonical_bytes` embed the payload it was given; every LATER call
   * with the same source id returns THAT SAME stored event, ignoring the
   * newly-supplied payload (the real Step-2 idempotency short-circuit).
   */
  function installIdempotentEngine(): void {
    const stored = new Map<string, Record<string, unknown>>();
    let seq = 0;
    appendMock.mockReset();
    appendMock.mockImplementation(
      (_tx: unknown, request: { event_type: string; source_event_id: string; payload: unknown }) => {
        const existing = stored.get(request.source_event_id);
        if (existing !== undefined) return Promise.resolve(existing);
        seq += 1;
        const event = {
          id: `${request.event_type}-fiscal-event-${String(seq)}`,
          event_type: request.event_type,
          sequence_number: seq,
          current_hash: 'h'.repeat(64),
          previous_hash: 'p'.repeat(64),
          event_version: 1,
          canonical_bytes: JSON.stringify({ payload: request.payload }),
        };
        stored.set(request.source_event_id, event);
        return Promise.resolve(event);
      },
    );
  }

  const STABLE_SOURCE_IDS = {
    approval: 'approval-uuid-stable',
    override: 'override-uuid-stable',
  };

  beforeEach(() => {
    installIdempotentEngine();
  });

  it('returns the SAME approval_id when authoring twice with the same sourceEventIds', async () => {
    const first = await authorPosOverride(baseInput({ sourceEventIds: STABLE_SOURCE_IDS }));
    const second = await authorPosOverride(baseInput({ sourceEventIds: STABLE_SOURCE_IDS }));

    expect(second.approval_id).toBe(first.approval_id);
    // …and the resolved events are literally the same rows.
    expect(second.approval_event_id).toBe(first.approval_event_id);
    expect(second.override_event_id).toBe(first.override_event_id);
  });

  it('the returned approval_id is the one actually SIGNED into the resolved approval event', async () => {
    const first = await authorPosOverride(baseInput({ sourceEventIds: STABLE_SOURCE_IDS }));
    const second = await authorPosOverride(baseInput({ sourceEventIds: STABLE_SOURCE_IDS }));

    // The projector cross-checks `approval_references[i].approval_id`
    // against BOTH resolved events' own payloads; a mismatch is a
    // non-retryable dead-letter.
    const approvalCall = appendMock.mock.calls[0] as [unknown, { payload: { approval_id: string } }];
    const signedApprovalId = approvalCall[1].payload.approval_id;
    expect(first.approval_id).toBe(signedApprovalId);
    expect(second.approval_id).toBe(signedApprovalId);
  });

  it('a re-authored OVERRIDE event carries the ORIGINAL approval_id (partial-crash recovery)', async () => {
    // First attempt: approval appended, then the override append fails —
    // the classic crash-between-the-two window.
    await authorPosOverride(baseInput({ sourceEventIds: STABLE_SOURCE_IDS })).catch(() => undefined);
    const signedApprovalId = (
      appendMock.mock.calls[0] as [unknown, { payload: { approval_id: string } }]
    )[1].payload.approval_id;

    appendMock.mockClear();
    const retry = await authorPosOverride(baseInput({ sourceEventIds: STABLE_SOURCE_IDS }));

    // The override append on the retry must reference the ORIGINAL
    // approval_id, not a freshly minted one.
    const overrideCall = appendMock.mock.calls[1] as [unknown, { payload: { approval_id: string } }];
    expect(overrideCall[1].payload.approval_id).toBe(signedApprovalId);
    expect(retry.approval_id).toBe(signedApprovalId);
  });
});

/**
 * Round-2 fix (Codex re-review of finding 6) — identity is never re-minted.
 */
describe('authorPosOverride — unreadable approval identity fails closed (round 2)', () => {
  it('throws instead of minting a replacement approval_id when the signed bytes are unreadable', async () => {
    appendMock.mockReset();
    let seq = 0;
    appendMock.mockImplementation((_tx: unknown, request: { event_type: string }) => {
      seq += 1;
      return Promise.resolve({
        id: `${request.event_type}-fiscal-event-${String(seq)}`,
        event_type: request.event_type,
        sequence_number: seq,
        current_hash: 'h'.repeat(64),
        previous_hash: 'p'.repeat(64),
        event_version: 1,
        // Unreadable: not the chain envelope shape at all.
        canonical_bytes: 'not-json',
      });
    });

    await expect(authorPosOverride(baseInput())).rejects.toThrow(ApprovalIdentityUnreadableError);

    // The OVERRIDE event is never authored against a fabricated identity.
    expect(appendMock).toHaveBeenCalledTimes(1);
  });

  it('throws when the envelope parses but carries no approval_id', async () => {
    appendMock.mockReset();
    let seq = 0;
    appendMock.mockImplementation((_tx: unknown, request: { event_type: string }) => {
      seq += 1;
      return Promise.resolve({
        id: `${request.event_type}-fiscal-event-${String(seq)}`,
        event_type: request.event_type,
        sequence_number: seq,
        current_hash: 'h'.repeat(64),
        previous_hash: 'p'.repeat(64),
        event_version: 1,
        canonical_bytes: JSON.stringify({ payload: { approval_scope: 'void_or_return_override' } }),
      });
    });

    await expect(authorPosOverride(baseInput())).rejects.toThrow(ApprovalIdentityUnreadableError);
  });
});
