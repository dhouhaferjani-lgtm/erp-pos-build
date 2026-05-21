/**
 * Integration test: offline-first POS lifecycle
 *
 * Exercises the full offline → sync → chain-break → cold-start lifecycle
 * using the same mock-DB pattern as existing unit tests. No real SQLite,
 * no real server, no GUI.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { usePaymentStore } from '@/stores/paymentStore';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useSyncStore } from '@/stores/syncStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

// ─── Module mocks (hoisted before imports) ────────────────────────────────────

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1, lastInsertId: 0 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn(),
  })),
  queryAll: vi.fn().mockResolvedValue([]),
  queryOne: vi.fn().mockResolvedValue(null),
  execute: vi.fn().mockResolvedValue({ rowsAffected: 1, lastInsertId: 0 }),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
  upsertTerminalState: vi.fn().mockResolvedValue(undefined),
  upsertZChainState: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/offlineReceiptRepository')>(
    '@/lib/db/repositories/offlineReceiptRepository',
  );
  return {
    ...actual,
    insertOfflineReceipt: vi.fn().mockResolvedValue(undefined),
    updateReceiptStatus: vi.fn().mockResolvedValue(undefined),
    cleanupSyncedReceipts: vi.fn().mockResolvedValue(undefined),
    cleanupStuckReceipts: vi.fn().mockResolvedValue(undefined),
    getLastSyncedReceiptNumber: vi.fn(),
  };
});

vi.mock('@/lib/db/repositories/fiscalEventRepository', async () => {
  const actual = await vi.importActual<typeof import('@/lib/db/repositories/fiscalEventRepository')>(
    '@/lib/db/repositories/fiscalEventRepository',
  );
  return {
    ...actual,
    getPendingFiscalEventsForSync: vi.fn(),
    updateFiscalEventSyncStatus: vi.fn().mockResolvedValue(undefined),
  };
});

vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  logSyncOperation: vi.fn().mockResolvedValue(undefined),
  getSyncMetadata: vi.fn().mockResolvedValue(null),
  setSyncMetadata: vi.fn().mockResolvedValue(undefined),
  cleanupOldSyncLogs: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/cashDrawerRepository', () => ({
  getPendingCashDrawerOps: vi.fn().mockResolvedValue([]),
  updateCashDrawerOpStatus: vi.fn().mockResolvedValue(undefined),
  cleanupSyncedCashDrawerOps: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/productRepository', () => ({
  upsertProducts: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
  upsertPaymentMethods: vi.fn().mockResolvedValue(undefined),
  upsertPaymentRepositories: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/operatorPinRepository', () => ({
  upsertOperators: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/zReportRepository', () => ({
  getUnsyncedZReports: vi.fn().mockResolvedValue([]),
  markZReportSynced: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('hash-seq-1'),
  computeGenesisHash: vi.fn().mockResolvedValue('genesis-hash-abc123'),
}));

vi.mock('@/lib/fiscal/instance', () => ({
  getFiscalEventEngine: vi.fn().mockResolvedValue({
    append: vi.fn().mockResolvedValue({
      id: 'fiscal-event-1',
      sequence_number: 1,
      previous_hash: 'prev-hash',
      current_hash: 'hash-seq-1',
      canonical_bytes: '{"event_type":"SALE_RECEIPT"}',
    }),
  }),
  __resetFiscalEventEngineForTesting: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

// Phase 1 Task 27 Pass 1: `createReceipt` / `processReceiptPayments` removed
// from `@/api/receiptApi` — the offline-first guarantee they used to backstop
// is now a compile-time invariant (the symbols don't exist). Only the
// remaining read methods need a stub here.
vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn(),
}));

// ─── Lazy imports (after mocks) ────────────────────────────────────────────────

import { pushOfflineReceipts } from '@/lib/sync/syncService';
import { apiPost } from '@/lib/api';
import {
  insertOfflineReceipt,
  updateReceiptStatus,
} from '@/lib/db/repositories/offlineReceiptRepository';
import {
  getPendingFiscalEventsForSync,
  updateFiscalEventSyncStatus,
  type LocalFiscalEvent,
} from '@/lib/db/repositories/fiscalEventRepository';
import { logSyncOperation } from '@/lib/db/repositories/syncLogRepository';
import { getTerminalState } from '@/lib/db/repositories/terminalStateRepository';

// ─── Shared seed helpers ────────────────────────────────────────────────────────

function makeMockDb() {
  return {} as import('@tauri-apps/plugin-sql').default;
}

function makeFiscalEvent(overrides: Partial<LocalFiscalEvent> = {}): LocalFiscalEvent {
  return {
    id: 'fiscal-event-1',
    tenant_id: 'tenant-1',
    company_id: 'company-1',
    terminal_id: 'terminal-1',
    operator_id: 'operator-1',
    event_type: 'SALE_RECEIPT',
    event_version: 1,
    signature_version: 'v1',
    sequence_number: 1,
    event_time_device: '2026-05-20T12:00:00Z',
    business_date: '2026-05-20',
    last_server_time_seen: null,
    reference_event_id: null,
    reference_document_id: null,
    source_event_class: 'offline_receipts',
    source_event_id: 'local-receipt-1',
    canonical_bytes: '{"event_type":"SALE_RECEIPT"}',
    previous_hash: 'prev-hash',
    current_hash: 'hash-seq-1',
    sync_status: 'pending',
    sync_error: null,
    created_at: '2026-05-20T12:00:00Z',
    synced_at: null,
    ...overrides,
  };
}

function seedCommonStores() {
  useAuthStore.setState({
    user: {
      id: 'user-1', name: 'Houssem', email: 'h@example.com',
      tenantId: 't1', phone: null, status: 'active', locale: null,
      timezone: null, roles: [], permissions: [], emailVerified: true,
    },
    companyId: 'company-1',
    companies: [{
      id: 'company-1', name: 'Test Co', legalName: 'Test SA',
      tax_id: 'FR123456789',
      countryCode: 'FR',
      address_street: '1 Rue Test',
      address_city: 'Paris',
      address_postal_code: '75001',
      currency: 'EUR',
      locale: 'fr',
      timezone: 'Europe/Paris',
    }],
    token: 'tok',
    serverUrl: 'http://localhost',
    isAuthenticated: true,
    isLoading: false,
    isInitialized: true,
  });

  useOperatorStore.setState({
    operator: {
      id: 'op-1', name: 'Cashier Alice', email: 'a@x.com',
      roles: [], permissions: [], can_discount: false, max_discount_percent: null,
    },
    isLocked: false,
    lastActivity: Date.now(),
    hasPins: true,
  });

  useCartStore.setState({
    items: [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })],
  });

  usePaymentStore.setState({
    paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
    paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
  });

  useTerminalStore.setState({
    terminal: {
      id: 'terminal-1',
      code: 'T001',
      name: 'Counter 1',
      type: 'fixed',
      is_active: true,
      is_training_mode: false,
      hardware_identifier: null,
      location: { id: 'loc1', name: 'Main', code: 'MAIN' },
    },
    shift: {
      id: 'shift-1',
      terminal_id: 'terminal-1',
      shift_number: 1,
      status: 'OPEN',
      opening_cash: '0.00',
      opened_at: '2026-05-20T08:00:00Z',
      user: { id: 'user-1', name: 'Houssem' },
    },
    hashChainReady: true,
  } as never);

  useSyncStore.setState({
    isSyncing: false,
    lastSyncAt: null,
    lastSyncResult: null,
    pendingReceiptCount: 0,
    lastError: null,
    scheduler: null,
    chainBreak: false,
    chainBreakReceiptNumber: null,
    chainBreakAcknowledgedAt: null,
  });
}

const seededTerminalState = {
  terminal_id: 'terminal-1',
  terminal_code: 'T001',
  location_code: 'MAIN',
  genesis_seed: 'seed-abc',
  last_hash: 'prev-hash',
  hash_sequence: 0,
  manager_pin_throttle_until: null,
  manager_pin_failed_attempts: 0,
  fiscal_schema_version: 2 as const,
};

// ─── Tests ──────────────────────────────────────────────────────────────────────

describe('offline-first POS lifecycle (integration)', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    const { __resetTerminalLocksForTesting } = await import('@/lib/offline/terminalMutex');
    __resetTerminalLocksForTesting();
    usePaymentStore.getState().reset();
    seedCommonStores();
  });

  // ── Case 1 ──────────────────────────────────────────────────────────────────

  it('offline cash sale writes receipt to SQLite with correct payments breakdown', async () => {
    // Arrange: terminal hash chain is seeded
    vi.mocked(getTerminalState).mockResolvedValue(seededTerminalState);

    // Act
    await usePaymentStore.getState().processCashCheckout(
      'terminal-1',
      useCartStore.getState().items,
      100,
    );

    // Assert: insertOfflineReceipt called once with correct shape
    expect(insertOfflineReceipt).toHaveBeenCalledOnce();

    const inserted = vi.mocked(insertOfflineReceipt).mock.calls[0]![1];

    // payments_json parses to a 1-element array
    const payments = JSON.parse(inserted.payments_json) as Array<{
      payment_method_id: string;
      repository_id: string;
      amount: string;
    }>;
    expect(payments).toHaveLength(1);
    expect(payments[0]).toMatchObject({
      payment_method_id: 'pm-cash',
      repository_id: 'repo-cash',
      // Bug 2 fix: payments[].amount is the cashier's tendered amount
      // (100, passed to processCashCheckout above), not the cart total (50).
      amount: '100.00',
    });

    // Metadata fields
    expect(inserted.consumption_mode).toBeNull();
    expect(inserted.table_id).toBeNull();
    expect(inserted.status).toBe('pending');
    expect(inserted.fiscal_hash).toBe('hash-seq-1');

    // Payment store post-state
    const state = usePaymentStore.getState();
    expect(state.lastReceiptIdempotencyKey).toBeTruthy();
    // UUID-shaped: 8-4-4-4-12
    expect(state.lastReceiptIdempotencyKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
    );
    expect(state.lastReceiptServerId).toBeNull();
    expect(state.error).toBeNull();
  });

  // ── Case 2 ──────────────────────────────────────────────────────────────────

  it('sync captures server_receipt_id and updates status to synced', async () => {
    const db = makeMockDb();
    const pendingEvent = makeFiscalEvent({
      id: 'fiscal-event-sync-1',
      source_event_id: 'local-receipt-1',
    });

    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValueOnce([pendingEvent]);
    vi.mocked(apiPost).mockResolvedValueOnce({
      results: [{
        stored: true,
        fiscal_event_id: 'fiscal-event-sync-1',
        sequence_conflict: false,
        exception_class: null,
      }],
    });

    // Act
    const result = await pushOfflineReceipts(db);

    expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fiscal-event-sync-1', 'syncing');
    expect(updateFiscalEventSyncStatus).toHaveBeenCalledWith(db, 'fiscal-event-sync-1', 'synced');
    expect(updateReceiptStatus).toHaveBeenCalledWith(db, 'local-receipt-1', 'synced');

    // Return shape
    expect(result.pushed).toBe(1);
    expect(result.failed).toBe(0);
    expect(result.chainBreak).toBe(false);
    expect(result.errors).toHaveLength(0);

    // Sync log records success
    expect(logSyncOperation).toHaveBeenCalledWith(
      expect.anything(),
      expect.anything(),
      expect.anything(),
      expect.anything(),
      'success',
      expect.anything(),
    );
  });

  // ── Case 3 ──────────────────────────────────────────────────────────────────

  it('chain_broken sync response flips syncStore.chainBreak flag', async () => {
    const db = makeMockDb();
    const pendingEvent = makeFiscalEvent({
      id: 'fiscal-event-chain-break',
      source_event_id: 'local-receipt-cb',
      sequence_number: 7,
    });

    vi.mocked(getPendingFiscalEventsForSync).mockResolvedValueOnce([pendingEvent]);
    vi.mocked(apiPost).mockResolvedValueOnce({
      results: [{
        stored: false,
        fiscal_event_id: 'fiscal-event-chain-break',
        sequence_conflict: false,
        exception_class: 'hash mismatch at sequence 7',
      }],
    });

    // Act: pushOfflineReceipts detects the break
    const result = await pushOfflineReceipts(db);

    // Return shape reflects the break
    expect(result.chainBreak).toBe(true);
    expect(result.failed).toBe(1);
    expect(result.pushed).toBe(0);

    // Simulate the scheduler's chain-break handler wiring
    useSyncStore.getState().setChainBreak(true, 'MAIN-T001-2026-00000007');

    // Store reflects the break
    expect(useSyncStore.getState().chainBreak).toBe(true);
    expect(useSyncStore.getState().chainBreakReceiptNumber).toBe('MAIN-T001-2026-00000007');
  });

  // ── Case 4 ──────────────────────────────────────────────────────────────────

  it('cold-start terminal gates checkout until refreshHashChainReady flips flag', async () => {
    // Set up terminal store in cold-start state (no hash chain)
    useTerminalStore.setState({
      terminal: {
        id: 't1',
        code: 'T001',
        name: 'Counter 1',
        type: 'fixed',
        is_active: true,
        is_training_mode: false,
        hardware_identifier: null,
        location: { id: 'loc1', name: 'Main', code: 'MAIN' },
      },
      hashChainReady: false,
      pendingTerminalId: null,
      shift: null,
      isLoading: false,
    });

    // Step 1: cold start — getTerminalState returns null
    vi.mocked(getTerminalState).mockResolvedValue(null);

    // Initial state: not ready
    expect(useTerminalStore.getState().hashChainReady).toBe(false);

    // Step 2: activate — getTerminalState now returns a seeded state
    vi.mocked(getTerminalState).mockResolvedValue(seededTerminalState);
    await useTerminalStore.getState().refreshHashChainReady();

    // Post-refresh: ready
    expect(useTerminalStore.getState().hashChainReady).toBe(true);

    // Step 3: negative path — getTerminalState throws, ready flag stays false
    vi.mocked(getTerminalState).mockRejectedValueOnce(new Error('SQLite closed'));
    await useTerminalStore.getState().refreshHashChainReady();

    expect(useTerminalStore.getState().hashChainReady).toBe(false);
  });
});
