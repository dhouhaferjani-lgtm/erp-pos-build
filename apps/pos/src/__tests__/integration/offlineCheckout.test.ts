import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock external dependencies
vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/api/receiptApi', () => ({
  createReceipt: vi.fn(),
  processReceiptPayments: vi.fn(),
  fetchReceipt: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn(),
  getAllPaymentRepositories: vi.fn(),
}));

vi.mock('@/stores/connectivityStore', () => ({
  useConnectivityStore: {
    getState: vi.fn().mockReturnValue({ isOnline: false, serverReachable: false, lastCheckedAt: Date.now() }),
    subscribe: vi.fn().mockReturnValue(() => {}),
  },
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'company-1',
      serverUrl: 'http://localhost',
      token: 'test-token',
      user: { id: 'user-1', name: 'Test Operator' },
      userId: 'user-1',
      companies: [{ id: 'company-1', currency: 'EUR' }],
    }),
  },
}));

vi.mock('@/lib/fiscal/hashService', () => ({
  computeFiscalHash: vi.fn().mockResolvedValue('integration-hash-abc'),
}));

vi.mock('@/lib/db/repositories/terminalStateRepository', () => ({
  getTerminalState: vi.fn(),
  advanceHashChain: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/lib/db/repositories/offlineReceiptRepository', () => ({
  insertOfflineReceipt: vi.fn().mockResolvedValue(undefined),
}));

import { usePaymentStore } from '@/stores/paymentStore';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { createReceipt } from '@/api/receiptApi';
import { getDatabase } from '@/lib/db';
import { getAllPaymentMethods, getAllPaymentRepositories } from '@/lib/db/repositories/paymentRepository';
import { getTerminalState } from '@/lib/db/repositories/terminalStateRepository';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

function makeMockDb() {
  return {
    execute: vi.fn().mockResolvedValue({ rowsAffected: 1 }),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn().mockResolvedValue(undefined),
  } as unknown as import('@tauri-apps/plugin-sql').default;
}

const mockCashMethod = makePaymentMethod({ id: 'pm-1', code: 'CASH', is_physical: true, has_maturity: false });
const mockCashRegister = makePaymentRepository({ id: 'repo-1', type: 'cash_register' });

const terminalState = {
  terminal_id: 'terminal-1',
  terminal_code: 'T001',
  location_code: 'MAIN',
  genesis_seed: 'seed-abc',
  last_hash: 'previous-hash-xyz',
  hash_sequence: 5,
};

describe('Offline Checkout Integration', () => {
  let db: ReturnType<typeof makeMockDb>;

  beforeEach(() => {
    usePaymentStore.getState().reset();
    vi.clearAllMocks();
    db = makeMockDb();
    vi.mocked(getDatabase).mockResolvedValue(db);
    vi.mocked(getTerminalState).mockResolvedValue(terminalState);
  });

  it('completes full offline cash checkout when network is down', async () => {
    // 1. Network is down — payment config falls back to SQLite
    vi.mocked(fetchPaymentMethods).mockRejectedValue(new Error('Network error'));
    vi.mocked(fetchPaymentRepositories).mockRejectedValue(new Error('Network error'));
    vi.mocked(getAllPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValue([mockCashRegister]);

    await usePaymentStore.getState().fetchPaymentConfig();

    // Verify payment config loaded from SQLite
    expect(usePaymentStore.getState().paymentMethods).toHaveLength(1);
    expect(usePaymentStore.getState().paymentMethods[0]!.id).toBe('pm-1');

    // 2. Checkout while offline
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: false,
      serverReachable: false,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });

    const items = [
      makeCartItem({ id: 'item-1', line_total: '100.00', tax_amount: '10.00' }),
      makeCartItem({ id: 'item-2', line_total: '50.00', tax_amount: '5.00' }),
    ];

    await usePaymentStore.getState().processCashCheckout(
      'terminal-1', items, 200,
    );

    // 3. Verify results
    const state = usePaymentStore.getState();
    expect(state.isOfflineReceipt).toBe(true);
    expect(state.lastReceipt).not.toBeNull();
    expect(state.lastReceipt!.receipt_number).toMatch(/^MAIN-T001-/);
    expect(state.lastReceipt!.total).toBe('150.00');
    expect(state.changeDue).toBe(50);
    expect(state.isProcessing).toBe(false);
    expect(state.error).toBeNull();

    // 4. API was never called for checkout
    expect(createReceipt).not.toHaveBeenCalled();
  });

  it('falls back to offline when online checkout fails mid-flight', async () => {
    // Payment config loads from API (online at this point)
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    // Online but API fails during checkout
    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: true,
      serverReachable: true,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });

    vi.mocked(createReceipt).mockRejectedValue(new Error('Connection reset'));

    const items = [makeCartItem({ line_total: '75.00', tax_amount: '0.00' })];
    await usePaymentStore.getState().processCashCheckout('terminal-1', items, 100);

    const state = usePaymentStore.getState();
    expect(state.isOfflineReceipt).toBe(true);
    expect(state.lastReceipt!.total).toBe('75.00');
    expect(state.changeDue).toBe(25);
    // API was attempted but failed
    expect(createReceipt).toHaveBeenCalled();
  });

  it('clears offline state on clearLastReceipt', async () => {
    vi.mocked(fetchPaymentMethods).mockRejectedValue(new Error('Network error'));
    vi.mocked(fetchPaymentRepositories).mockRejectedValue(new Error('Network error'));
    vi.mocked(getAllPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    vi.mocked(useConnectivityStore.getState).mockReturnValue({
      isOnline: false,
      serverReachable: false,
      lastCheckedAt: Date.now(),
      checkNow: vi.fn(),
      startMonitoring: vi.fn(),
    });

    const items = [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })];
    await usePaymentStore.getState().processCashCheckout('terminal-1', items, 50);

    expect(usePaymentStore.getState().isOfflineReceipt).toBe(true);

    usePaymentStore.getState().clearLastReceipt();

    expect(usePaymentStore.getState().isOfflineReceipt).toBe(false);
    expect(usePaymentStore.getState().lastReceipt).toBeNull();
  });
});
