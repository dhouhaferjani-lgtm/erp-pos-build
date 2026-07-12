/**
 * Task 22 — wire `pullCustomers` into `runFullSync` + `SyncResult` contract.
 *
 * Regression guard: after Task 21 shipped pullCustomers it was left orphaned
 * (zero callers). These tests verify the integration is complete:
 *   (a) `runFullSync` CALLS `pullCustomers` with the tenant/company IDs from
 *       the authStore (regression-guards the orphan).
 *   (b) the result carries `customersPulled: number`.
 *   (c) when `pullCustomers` throws the tick CONTINUES and the result reflects
 *       `customersFailed: true` + `degraded: true`.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

// ─── Module mocks (hoisted by Vitest above all imports) ───────────────────────

vi.mock('@/lib/api', () => {
  class ApiRequestError extends Error {
    constructor(
      public status: number,
      message: string,
      public code: string,
    ) {
      super(message);
      this.name = 'ApiRequestError';
    }
  }
  return {
    apiGet: vi.fn(),
    apiPost: vi.fn().mockResolvedValue({}),
    apiPostRaw: vi.fn().mockResolvedValue({ results: [] }),
    ApiRequestError,
  };
});

// The target: mock pullCustomers so we can control its behaviour per-test.
vi.mock('@/lib/customer/customerSyncService', () => ({
  pullCustomers: vi.fn().mockResolvedValue(0),
}));

// T-0001 Part B target: pushPendingCustomers must run in the SAME tick,
// BEFORE pullCustomers.
vi.mock('@/lib/customer/pendingCustomerSyncService', () => ({
  pushPendingCustomers: vi.fn().mockResolvedValue(0),
}));

// Replenishment sync service — mocked so the push/pull don't hit the mock db
// and so the baseline tick stays clean (real pushReplenishmentRequests would
// throw on the `{}` mock db and push a degrading error).
vi.mock('@/lib/replenishment/replenishmentSyncService', () => ({
  pushReplenishmentRequests: vi.fn().mockResolvedValue(undefined),
  pullOpenReplenishment: vi.fn().mockResolvedValue(undefined),
}));

// GB-3 — the outbox retention sweeps run in the push phase. Mock the two
// repositories' purge helpers so a test can force a purge throw and assert the
// tick swallows it. `importActual` preserves every other export syncService
// pulls transitively.
vi.mock('@/lib/db/repositories/replenishmentOutboxRepository', async () => {
  const actual = await vi.importActual<
    typeof import('@/lib/db/repositories/replenishmentOutboxRepository')
  >('@/lib/db/repositories/replenishmentOutboxRepository');
  return { ...actual, purgeOutboxRows: vi.fn().mockResolvedValue(undefined) };
});
vi.mock('@/lib/db/repositories/pendingCustomerRepository', async () => {
  const actual = await vi.importActual<
    typeof import('@/lib/db/repositories/pendingCustomerRepository')
  >('@/lib/db/repositories/pendingCustomerRepository');
  return { ...actual, purgeOutboxRows: vi.fn().mockResolvedValue(undefined) };
});

vi.mock('@/lib/db/repositories/offlineReceiptRepository', async () => {
  const actual = await vi.importActual<
    typeof import('@/lib/db/repositories/offlineReceiptRepository')
  >('@/lib/db/repositories/offlineReceiptRepository');
  return {
    ...actual,
    updateReceiptStatus: vi.fn().mockResolvedValue(undefined),
    cleanupSyncedReceipts: vi.fn().mockResolvedValue(undefined),
    cleanupStuckReceipts: vi.fn().mockResolvedValue(undefined),
  };
});

vi.mock('@/lib/db/repositories/fiscalEventRepository', async () => {
  const actual = await vi.importActual<
    typeof import('@/lib/db/repositories/fiscalEventRepository')
  >('@/lib/db/repositories/fiscalEventRepository');
  return {
    ...actual,
    getPendingFiscalEventsForSync: vi.fn().mockResolvedValue([]),
    recoverStrandedSyncingFiscalEvents: vi.fn().mockResolvedValue(0),
    updateFiscalEventSyncStatus: vi.fn().mockResolvedValue(undefined),
  };
});

vi.mock('@/lib/db/repositories/cashDrawerRepository', () => ({
  getPendingCashDrawerOps: vi.fn().mockResolvedValue([]),
  updateCashDrawerOpStatus: vi.fn().mockResolvedValue(undefined),
  cleanupSyncedCashDrawerOps: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
  cleanupOldSyncLogs: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  upsertProducts: vi.fn().mockResolvedValue(undefined),
  deleteProducts: vi.fn().mockResolvedValue(undefined),
  reconcileMenuProducts: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/locationStockRepository', () => ({
  deleteForProducts: vi.fn().mockResolvedValue(undefined),
  upsertStockRows: vi.fn().mockResolvedValue(undefined),
  replaceAllStock: vi.fn().mockResolvedValue(undefined),
  replaceIncoming: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/api/stockApi', () => ({
  fetchLocationStock: vi.fn().mockResolvedValue({
    data: { stock: [], incoming: [], as_of: '2026-01-01T00:00:00Z' },
    meta: { pagination: { current_page: 1, last_page: 1, total: 0 } },
  }),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  upsertPaymentMethods: vi.fn().mockResolvedValue(undefined),
  upsertPaymentRepositories: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  upsertOperators: vi.fn().mockResolvedValue(undefined),
  pruneOperatorsExcept: vi.fn().mockResolvedValue(0),
}));

vi.mock('@/lib/db/repositories/queuedPinUpdateRepository', () => ({
  getPendingPinUpdates: vi.fn().mockResolvedValue([]),
  markPinUpdateSynced: vi.fn().mockResolvedValue(undefined),
  markPinUpdateFailed: vi.fn().mockResolvedValue(undefined),
  enqueuePinUpdate: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/tableRepository', () => ({
  upsertFloors: vi.fn().mockResolvedValue(undefined),
  upsertTables: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/menuRepository', () => ({
  upsertMenuCategories: vi.fn().mockResolvedValue(undefined),
  upsertMenuCategoryItems: vi.fn().mockResolvedValue(undefined),
  deleteMenuCategories: vi.fn().mockResolvedValue(undefined),
  deleteMenuCategoryItems: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  upsertTerminalState: vi.fn().mockResolvedValue(undefined),
  setShiftNumberSeed: vi.fn().mockResolvedValue(undefined),
  upsertZChainState: vi.fn().mockResolvedValue(undefined),
  getZChainState: vi.fn().mockResolvedValue(null),
  FiscalRegressionError: class FiscalRegressionError extends Error {
    constructor(
      readonly terminalId: string,
      readonly op: string,
      readonly before: number,
      readonly after: number,
    ) {
      super(`${op} regression: ${before} -> ${after}`);
      this.name = 'FiscalRegressionError';
    }
  },
}));

vi.mock('@/lib/db/repositories/zReportRepository', () => ({
  getUnsyncedZReports: vi.fn().mockResolvedValue([]),
  markZReportSynced: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/fiscal/hashService', () => ({
  computeGenesisHash: vi.fn().mockResolvedValue('genesis-hash-abc123'),
}));

vi.mock('@/lib/offline/voucherRepository', () => ({
  upsertVouchers: vi.fn().mockResolvedValue(undefined),
  upsertVoucherLedgerEntries: vi.fn().mockResolvedValue(undefined),
  upsertReceiptQrIndexEntries: vi.fn().mockResolvedValue(undefined),
  getPendingVoucherLedgerEntries: vi.fn().mockResolvedValue([]),
  markVoucherLedgerEntrySynced: vi.fn().mockResolvedValue(undefined),
  markVoucherLedgerEntryFailed: vi.fn().mockResolvedValue(undefined),
}));

// authStore mock — happy-path default: both tenantId and companyId present.
// Individual tests override via vi.mocked(useAuthStore.getState).mockReturnValue(…).
vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      user: { id: 'u1', tenantId: 'tenant-1' },
      companyId: 'company-1',
      refreshCompanyConfig: vi.fn().mockResolvedValue(undefined),
    }),
  },
}));

// ─── Imports (run AFTER mocks are hoisted) ────────────────────────────────────

import { runFullSync } from '../syncService';
import { pullCustomers } from '@/lib/customer/customerSyncService';
import { pushPendingCustomers } from '@/lib/customer/pendingCustomerSyncService';
import { purgeOutboxRows as purgeReplenishmentOutbox } from '@/lib/db/repositories/replenishmentOutboxRepository';
import { purgeOutboxRows as purgePendingCustomerOutbox } from '@/lib/db/repositories/pendingCustomerRepository';
import { useAuthStore } from '@/stores/authStore';
import { apiGet } from '@/lib/api';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function makeMockDb() {
  return {} as import('@tauri-apps/plugin-sql').default;
}

/**
 * Wire up the minimum apiGet sequence that runFullSync needs in its pull phase
 * so every pull function resolves cleanly and the assertions are unambiguous.
 *
 * Call order in runFullSync (pull phase):
 *   1. pullProducts           → [] (products)
 *   2. pullPaymentConfig      → [] + []  (methods + repos — Promise.all)
 *   3. pullOperatorPins       → []
 *   4. pullTerminalState      → terminal object
 *   5. pullZChainState        → z-chain object
 *   6. pullTables             → { data: [] }
 *   7. pullActiveMenu         → { categories: [] }
 *   8. pullVouchers           → { vouchers: [] }
 *   9. pullVoucherLedger      → { entries: [] }
 *  10. pullReceiptQrIndex     → { entries: [] }
 *
 * pullCustomers is mocked at module level (returns 0) so it does NOT consume
 * any apiGet slot. pullProductVariants / pullLocationStock use separate APIs
 * (fetchVariants / fetchLocationStock) already mocked above.
 */
function setupApiGetSequence() {
  vi.mocked(apiGet)
    .mockResolvedValueOnce([]) // products
    .mockResolvedValueOnce([]) // payment methods (Promise.all[0])
    .mockResolvedValueOnce([]) // payment repos  (Promise.all[1])
    .mockResolvedValueOnce([]) // operator pins
    .mockResolvedValueOnce({   // terminal state
      id: 'terminal-1',
      code: 'T001',
      location: { code: null },
      genesis_seed: 'seed-abc',
      last_hash: 'hash-xyz',
      hash_sequence: 0,
      fiscal_schema_version: 2,
    })
    .mockResolvedValueOnce({ // z-chain state
      z_last_hash: null,
      z_hash_sequence: 0,
      z_number: 0,
      grand_totals: null,
    })
    .mockResolvedValueOnce({ data: [] }) // pullTables
    .mockResolvedValueOnce({ categories: [] }) // pullActiveMenu
    .mockResolvedValueOnce({ vouchers: [] }) // pullVouchers
    .mockResolvedValueOnce({ entries: [] }) // pullVoucherLedger
    .mockResolvedValueOnce({ entries: [] }); // pullReceiptQrIndex
}

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('Task 22 — pullCustomers wired into runFullSync', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();

    // Reset to happy-path default (tenantId + companyId present) after each
    // clearAllMocks wipes mockReturnValue state.
    vi.mocked(useAuthStore.getState).mockReturnValue({
      user: { id: 'u1', tenantId: 'tenant-1' },
      companyId: 'company-1',
      refreshCompanyConfig: vi.fn().mockResolvedValue(undefined),
    } as never);

    // Set up productStore with POS config so resolveCatalogTenantGate
    // routes to 'standard' without a config fetch API call.
    void (async () => {
      const { useProductStore } = await import('@/stores/productStore');
      useProductStore.setState({
        companyConfig: {
          company_id: 'company-1',
          all_enabled_modules: ['POS'],
        } as never,
      });
    })();

    setupApiGetSequence();
  });

  it('(a) calls pullCustomers with tenantId and companyId from authStore', async () => {
    vi.mocked(pullCustomers).mockResolvedValueOnce(5);

    const result = await runFullSync(db, 'terminal-1');

    // Regression guard: the orphaned function must now be called.
    expect(pullCustomers).toHaveBeenCalledOnce();
    expect(pullCustomers).toHaveBeenCalledWith(db, 'tenant-1', 'company-1');

    // (b) result carries customersPulled.
    expect(result.customersPulled).toBe(5);
    // Clean run: no failure flag, tick not degraded from customers.
    expect(result.customersFailed).toBeFalsy();
  });

  it('(b) skips pullCustomers and sets customersFailed when tenantId/companyId is missing', async () => {
    // Override to simulate a session where context IDs are absent.
    vi.mocked(useAuthStore.getState).mockReturnValue({
      user: null,
      companyId: null,
      refreshCompanyConfig: vi.fn().mockResolvedValue(undefined),
    } as never);

    const result = await runFullSync(db, 'terminal-1');

    expect(pullCustomers).not.toHaveBeenCalled();
    expect(result.customersPulled).toBe(0);
    expect(result.customersFailed).toBe(true);
    // errors.push() triggers computeDegraded via errors.length > 0.
    expect(result.degraded).toBe(true);
  });

  it('(c) continues tick and sets customersFailed when pullCustomers throws', async () => {
    vi.mocked(pullCustomers).mockRejectedValueOnce(new Error('Network error'));

    // Must NOT reject — the tick finishes.
    const result = await runFullSync(db, 'terminal-1');

    expect(result.customersPulled).toBe(0);
    expect(result.customersFailed).toBe(true);
    // errors.push() with the caught message → degraded.
    expect(result.degraded).toBe(true);
    // paymentConfigPulled runs BEFORE the customer pull — must still succeed,
    // confirming the tick's push+pull phases completed independently.
    expect(result.paymentConfigPulled).toBe(true);
  });
});

// ─── T-0001 Part B — pushPendingCustomers wired into runFullSync ─────────────
//
// Root cause 2 of the "add customer never reaches the server" bug:
// pushPendingCustomers had ZERO callers — the outbox filled up forever.
// It must drain BEFORE pullCustomers in the same tick so the pull sees the
// server-created Partner rows and the client→server alias promotion
// completes in one cycle.

describe('T-0001 — pushPendingCustomers wired into runFullSync', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();

    vi.mocked(useAuthStore.getState).mockReturnValue({
      user: { id: 'u1', tenantId: 'tenant-1' },
      companyId: 'company-1',
      refreshCompanyConfig: vi.fn().mockResolvedValue(undefined),
    } as never);

    void (async () => {
      const { useProductStore } = await import('@/stores/productStore');
      useProductStore.setState({
        companyConfig: {
          company_id: 'company-1',
          all_enabled_modules: ['POS'],
        } as never,
      });
    })();

    setupApiGetSequence();
  });

  it('calls pushPendingCustomers with tenant/company BEFORE pullCustomers and reports customersPushed', async () => {
    vi.mocked(pushPendingCustomers).mockResolvedValueOnce(2);

    const result = await runFullSync(db, 'terminal-1');

    // Regression guard: the orphaned push function must now be called.
    expect(pushPendingCustomers).toHaveBeenCalledOnce();
    expect(pushPendingCustomers).toHaveBeenCalledWith(db, 'tenant-1', 'company-1');

    // Ordering: push must complete before the pull starts, so the pull's
    // delta window includes the customers the push just created server-side.
    // (Fallbacks make a missing invocation fail the comparison loudly.)
    const pushOrder =
      vi.mocked(pushPendingCustomers).mock.invocationCallOrder[0] ?? Number.POSITIVE_INFINITY;
    const pullOrder =
      vi.mocked(pullCustomers).mock.invocationCallOrder[0] ?? Number.NEGATIVE_INFINITY;
    expect(pushOrder).toBeLessThan(pullOrder);

    expect(result.customersPushed).toBe(2);
    expect(result.customersFailed).toBeFalsy();
  });

  it('continues to pullCustomers (and the rest of the tick) when the push throws', async () => {
    vi.mocked(pushPendingCustomers).mockRejectedValueOnce(new Error('Network error'));
    vi.mocked(pullCustomers).mockResolvedValueOnce(3);

    const result = await runFullSync(db, 'terminal-1');

    // Push failure is isolated: pull still runs in the same tick.
    expect(pullCustomers).toHaveBeenCalledOnce();
    expect(result.customersPushed).toBe(0);
    expect(result.customersPulled).toBe(3);
    // Surfaced as a degraded-tick error, but NOT as a pull failure.
    expect(result.customersFailed).toBeFalsy();
    expect(result.errors.some((e) => e.includes('Customer push failed'))).toBe(true);
    expect(result.degraded).toBe(true);
  });

  it('skips the push when tenant/company context is missing', async () => {
    vi.mocked(useAuthStore.getState).mockReturnValue({
      user: null,
      companyId: null,
      refreshCompanyConfig: vi.fn().mockResolvedValue(undefined),
    } as never);

    const result = await runFullSync(db, 'terminal-1');

    expect(pushPendingCustomers).not.toHaveBeenCalled();
    expect(result.customersPushed).toBe(0);
  });
});

// ─── GB-3 — outbox retention sweep wired into runFullSync ────────────────────
//
// Both outbox purges run in the push phase after the pushes complete. A purge
// failure is swallow-and-logged like the pull steps: it must NEVER reject the
// tick nor degrade it (rows are inert; next tick retries).

describe('GB-3 — outbox retention sweep wired into runFullSync', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    vi.clearAllMocks();
    db = makeMockDb();

    vi.mocked(useAuthStore.getState).mockReturnValue({
      user: { id: 'u1', tenantId: 'tenant-1' },
      companyId: 'company-1',
      refreshCompanyConfig: vi.fn().mockResolvedValue(undefined),
    } as never);

    void (async () => {
      const { useProductStore } = await import('@/stores/productStore');
      useProductStore.setState({
        companyConfig: {
          company_id: 'company-1',
          all_enabled_modules: ['POS'],
        } as never,
      });
    })();

    setupApiGetSequence();
  });

  it('sweeps both outboxes with tenant/company after the pushes', async () => {
    const result = await runFullSync(db, 'terminal-1');

    expect(purgeReplenishmentOutbox).toHaveBeenCalledWith(db, 'tenant-1', 'company-1');
    expect(purgePendingCustomerOutbox).toHaveBeenCalledWith(db, 'tenant-1', 'company-1');
    // Clean tick: the sweep contributes nothing to the degraded signal.
    expect(result.degraded).toBe(false);
  });

  it('swallows a purge throw without rejecting or degrading the tick', async () => {
    vi.mocked(purgeReplenishmentOutbox).mockRejectedValueOnce(new Error('disk I/O error'));

    // Must NOT reject — the tick finishes.
    const result = await runFullSync(db, 'terminal-1');

    // A purge failure never degrades the tick (no errors.push).
    expect(result.degraded).toBe(false);
    expect(result.errors).toEqual([]);
    // The tick continued past the failed sweep: the pull phase still ran and
    // the second outbox was still swept.
    expect(pullCustomers).toHaveBeenCalledOnce();
    expect(purgePendingCustomerOutbox).toHaveBeenCalledWith(db, 'tenant-1', 'company-1');
  });
});
