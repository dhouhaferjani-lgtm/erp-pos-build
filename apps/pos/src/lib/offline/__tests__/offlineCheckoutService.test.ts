import { describe, it, expect, vi, beforeEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: {
    getState: vi.fn().mockReturnValue({ isOnline: true }),
  },
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn().mockReturnValue({ triggerSync: vi.fn() }),
  },
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(),
}));

vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('mock-hash'),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  insertOfflineReceipt: vi.fn(),
}));

import { executeCheckout, type CheckoutInput } from '../offlineCheckoutService';
import { buildCheckoutPolicySnapshot } from '@/lib/payment/checkoutPolicySnapshot';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { createOfflineReceipt } from '@/lib/offline/receiptService';
import { makeCartItem } from '@/test/helpers';

function makeMockDb() {
  return {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

function makeInput(overrides: Partial<CheckoutInput> = {}): CheckoutInput {
  return {
    tenantId: 'tenant-1',
    companyId: 'company-1',
    terminalId: 'terminal-1',
    operatorId: 'op-1',
    operatorName: 'Test Operator',
    shiftId: 'shift-1',
    cartItems: [makeCartItem({ line_total: '50.00', tax_amount: '5.00' })],
    currency: 'EUR',
    seller: {
      name: 'Test SA',
      taxNumber: '123456789',
      countryCode: 'FR',
      street: '1 Rue Test',
      city: 'Paris',
      postalCode: '75001',
    },
    paymentMethodId: 'pm-1',
    paymentRepositoryId: 'repo-1',
    tenderedAmount: '100.00',
    receiptData: { terminal_id: 'terminal-1', lines: [] },
    // The sealed checkout decision is an INPUT to this wrapper, not something
    // it derives: it has no payment policy, terminal or shift context of its
    // own. A null policy closes the rounding gate, so this fixture is today's
    // exact behaviour.
    policySnapshot: buildCheckoutPolicySnapshot({
      exactTotal: '50.00',
      currency: 'EUR',
      legs: [{ methodCode: 'CASH', amount: '100.00' }],
      tenderedAmount: '100.00',
      isCashMethodCode: (code) => code === 'CASH',
      policy: null,
      fiscalSchemaVersion: 3,
      invoiceType: 'SALE',
      autoAcceptCountThisShift: 0,
    }),
    ...overrides,
  };
}

function makeOfflineResult(
  overrides: Partial<Awaited<ReturnType<typeof createOfflineReceipt>>> = {},
) {
  return {
    receiptNumber: 'MAIN-T001-2026-00000001',
    total: '50.00',
    subtotal: '50.00',
    taxAmount: '5.00',
    discountAmount: '0.00',
    vatBreakdown: [],
    changeDue: '50.00',
    fiscalHash: 'offline-hash-123',
    idempotencyKey: 'idem-001',
    localId: 'local-id-001',
    ...overrides,
  } satisfies Awaited<ReturnType<typeof createOfflineReceipt>>;
}

/**
 * Phase 1 Task 27 Pass 1 (spec §14.3): `executeCheckout` is now
 * connectivity-independent. The device ALWAYS authors locally via
 * `createOfflineReceipt()`; connectivity only decides whether an immediate
 * sync flush follows. The pre-Pass-1 `onlineCheckout()` branch and its
 * `createReceipt()` / `processReceiptPayments()` callers are deleted.
 *
 * These tests lock the Pass 1 contract:
 *   1. ONLINE  → local-author + immediate sync-flush kick
 *   2. OFFLINE → local-author + NO sync-flush kick (scheduler picks up later)
 *   3. Symmetric authoring shape — `result.fiscalHash` is set in both cases
 *   4. Source-level guard: the file no longer imports the server-authoring
 *      receipt methods, and the `onlineCheckout` symbol is gone.
 */
describe('offlineCheckoutService - executeCheckout (Phase 1 Task 27 Pass 1)', () => {
  let db: ReturnType<typeof makeMockDb>;
  let triggerSync: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();
    triggerSync = vi.fn();
    vi.mocked(useSyncStore.getState).mockReturnValue({
      triggerSync,
      // Other syncStore fields are not exercised by executeCheckout; the
      // cast keeps TS happy without forcing the full SyncStore shape.
    } as unknown as ReturnType<typeof useSyncStore.getState>);
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: true,
      serverReachable: true,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });
    vi.mocked(createOfflineReceipt).mockResolvedValue(makeOfflineResult());
  });

  it('authors locally via createOfflineReceipt when ONLINE — no server-authoring methods are reachable', async () => {
    const result = await executeCheckout(db, makeInput());

    expect(createOfflineReceipt).toHaveBeenCalledOnce();
    expect(result.receiptId).toBe('local-local-id-001');
    expect(result.receiptNumber).toBe('MAIN-T001-2026-00000001');
    expect(result.fiscalHash).toBe('offline-hash-123');
    // Pass 1: `isOffline` describes sync posture, not authoring posture.
    // Online connectivity → kick fired → isOffline=false.
    expect(result.isOffline).toBe(false);
  });

  it('authors locally via createOfflineReceipt when OFFLINE (symmetric authoring)', async () => {
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: false,
      serverReachable: false,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });
    vi.mocked(createOfflineReceipt).mockResolvedValue(
      makeOfflineResult({
        receiptNumber: 'MAIN-T001-2026-00000002',
        fiscalHash: 'offline-hash-offline',
      }),
    );

    const result = await executeCheckout(db, makeInput());

    expect(createOfflineReceipt).toHaveBeenCalledOnce();
    expect(result.receiptNumber).toBe('MAIN-T001-2026-00000002');
    expect(result.fiscalHash).toBe('offline-hash-offline');
    expect(result.isOffline).toBe(true);
  });

  it('triggers an immediate sync flush when ONLINE', async () => {
    await executeCheckout(db, makeInput());
    expect(triggerSync).toHaveBeenCalledOnce();
  });

  it('does NOT trigger an immediate sync flush when OFFLINE (scheduler picks up later)', async () => {
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: false,
      serverReachable: false,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });

    await executeCheckout(db, makeInput());

    expect(triggerSync).not.toHaveBeenCalled();
  });

  it('result contains every field the success modal needs (subtotal / tax / discount / change_due / hash)', async () => {
    vi.mocked(createOfflineReceipt).mockResolvedValue(
      makeOfflineResult({
        receiptNumber: 'MAIN-T001-2026-00000004',
        total: '45.00',
        subtotal: '50.00',
        taxAmount: '5.00',
        discountAmount: '5.00',
        vatBreakdown: [],
        changeDue: '55.00',
        fiscalHash: 'hash-abc',
      }),
    );

    const result = await executeCheckout(db, makeInput());

    expect(result.receiptNumber).toBe('MAIN-T001-2026-00000004');
    expect(result.total).toBe('45.00');
    expect(result.subtotal).toBe('50.00');
    expect(result.taxAmount).toBe('5.00');
    expect(result.discountAmount).toBe('5.00');
    expect(result.changeDue).toBe('55.00');
    expect(result.fiscalHash).toBe('hash-abc');
  });

  it('forwards `input.payments` verbatim into createOfflineReceipt (instrument fields preserved)', async () => {
    const input = makeInput({
      payments: [
        {
          methodCode: 'CASH',
          amount: '25.00',
          paymentMethodId: 'pm-cash',
          repositoryId: 'repo-cash',
        },
        {
          methodCode: 'store_voucher',
          amount: '25.00',
          paymentMethodId: 'pm-store-voucher',
          repositoryId: 'repo-virtual',
          instrumentType: 'store_voucher',
          instrumentSerial: 'SV-2026-9999',
        },
      ],
    });

    await executeCheckout(db, input);

    expect(createOfflineReceipt).toHaveBeenCalledOnce();
    const [, callInput] = vi.mocked(createOfflineReceipt).mock.calls[0]!;
    expect(callInput.payments).toHaveLength(2);
    expect(callInput.payments![0]).toMatchObject({
      methodCode: 'CASH',
      amount: '25.00',
      paymentMethodId: 'pm-cash',
      repositoryId: 'repo-cash',
    });
    expect(callInput.payments![1]).toMatchObject({
      methodCode: 'store_voucher',
      amount: '25.00',
      paymentMethodId: 'pm-store-voucher',
      repositoryId: 'repo-virtual',
      instrumentType: 'store_voucher',
      instrumentSerial: 'SV-2026-9999',
    });
  });

  it('falls back to a single CASH payment when `input.payments` is absent', async () => {
    // Two items: 50.00 + 25.00 = 75.00 default CASH amount at EUR scale (2dp).
    const input = makeInput({
      cartItems: [
        makeCartItem({ line_total: '50.00', tax_amount: '5.00' }),
        makeCartItem({ line_total: '25.00', tax_amount: '2.50' }),
      ],
      payments: undefined,
    });

    await executeCheckout(db, input);

    const [, callInput] = vi.mocked(createOfflineReceipt).mock.calls[0]!;
    expect(callInput.payments).toEqual([{ methodCode: 'CASH', amount: '75.00' }]);
  });

  /**
   * Pass 1 source-level guard (spec §14.3 chokepoint disposition).
   *
   * Reads the source file of `offlineCheckoutService.ts` and asserts that
   * the deleted server-authoring identifiers are absent. This is the
   * regression net the plan calls for — a future re-introduction of either
   * `createReceipt` / `processReceiptPayments` import OR a fresh
   * `onlineCheckout()` helper would fail this assertion at unit-test time,
   * BEFORE the change reaches Codex / Opus review.
   *
   * Read the file from the repo path, NOT via `await import('...')` —
   * the source-level guard must be a string check on disk.
   */
  it('offlineCheckoutService.ts no longer imports the server-authoring receipt methods (source-level guard)', () => {
    const src = readFileSync(
      resolve(__dirname, '..', 'offlineCheckoutService.ts'),
      'utf8',
    );

    // No imports / calls to the deleted server-authoring receipt methods.
    // The negative-lookbehind excludes `createOfflineReceipt` (the LOCAL
    // authoring entry point we KEEP); `createReceipt` is the deleted
    // SERVER-authoring method.
    expect(src).not.toMatch(/(?<!Offline)createReceipt\b/);
    expect(src).not.toMatch(/\bprocessReceiptPayments\b/);
    // The `onlineCheckout` helper is gone (the branch it served is gone).
    expect(src).not.toMatch(/\bonlineCheckout\b/);
    // And the receiptApi module itself isn't imported anywhere in this file —
    // closes the route by which a future commit could re-add server-authoring
    // via some other name.
    expect(src).not.toMatch(/from\s+['"]@\/api\/receiptApi['"]/);
  });
});
