import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '../paymentStore';
import { makePaymentMethod, makePaymentRepository } from '@/test/helpers';

// Mock API modules
vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

// Phase 1 Task 27 Pass 1: `createReceipt` / `processReceiptPayments` removed
// from `@/api/receiptApi`. paymentStore's fallback path never called them
// anyway — stub only the remaining read methods.
vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn(),
}));

// Mock DB modules
vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn(),
  getAllPaymentRepositories: vi.fn(),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'company-1',
      serverUrl: 'http://localhost',
      token: 'test-token',
      companies: [{ id: 'company-1', currency: 'EUR' }],
    }),
  },
}));

import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';
import { getDatabase } from '@/lib/db';
import { getAllPaymentMethods, getAllPaymentRepositories } from '@/lib/db/repositories/paymentRepository';

const mockDb = {
  execute: vi.fn().mockResolvedValue({ rowsAffected: 0 }),
  select: vi.fn().mockResolvedValue([]),
  close: vi.fn().mockResolvedValue(undefined),
} as unknown as import('@tauri-apps/plugin-sql').default;

describe('paymentStore - SQLite fallback', () => {
  beforeEach(() => {
    usePaymentStore.getState().reset();
    vi.clearAllMocks();
    vi.mocked(getDatabase).mockResolvedValue(mockDb);
  });

  it('falls back to SQLite payment methods when API fails', async () => {
    const cachedMethod = makePaymentMethod({ id: 'pm-cached' });
    const cachedRepo = makePaymentRepository({ id: 'repo-cached' });

    vi.mocked(fetchPaymentMethods).mockRejectedValue(new Error('Network error'));
    vi.mocked(fetchPaymentRepositories).mockRejectedValue(new Error('Network error'));
    vi.mocked(getAllPaymentMethods).mockResolvedValue([cachedMethod]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValue([cachedRepo]);

    await usePaymentStore.getState().fetchPaymentConfig();

    const state = usePaymentStore.getState();
    expect(state.paymentMethods).toHaveLength(1);
    expect(state.paymentMethods[0]!.id).toBe('pm-cached');
    expect(state.paymentRepositories).toHaveLength(1);
    expect(state.paymentRepositories[0]!.id).toBe('repo-cached');
  });

  it('uses API data over SQLite when both are available', async () => {
    const apiMethod = makePaymentMethod({ id: 'pm-api' });
    const apiRepo = makePaymentRepository({ id: 'repo-api' });
    const cachedMethod = makePaymentMethod({ id: 'pm-cached' });
    const cachedRepo = makePaymentRepository({ id: 'repo-cached' });

    vi.mocked(getAllPaymentMethods).mockResolvedValue([cachedMethod]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValue([cachedRepo]);
    vi.mocked(fetchPaymentMethods).mockResolvedValue([apiMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([apiRepo]);

    await usePaymentStore.getState().fetchPaymentConfig();

    // API data takes precedence over SQLite cache
    expect(usePaymentStore.getState().paymentMethods[0]!.id).toBe('pm-api');
  });

  it('leaves paymentMethods empty when both API and SQLite fail', async () => {
    vi.mocked(fetchPaymentMethods).mockRejectedValue(new Error('Network error'));
    vi.mocked(fetchPaymentRepositories).mockRejectedValue(new Error('Network error'));
    vi.mocked(getDatabase).mockRejectedValue(new Error('DB not initialized'));

    await usePaymentStore.getState().fetchPaymentConfig();

    expect(usePaymentStore.getState().paymentMethods).toHaveLength(0);
  });

  it('leaves paymentMethods empty when API fails and SQLite returns empty', async () => {
    vi.mocked(fetchPaymentMethods).mockRejectedValue(new Error('Network error'));
    vi.mocked(fetchPaymentRepositories).mockRejectedValue(new Error('Network error'));
    vi.mocked(getAllPaymentMethods).mockResolvedValue([]);
    vi.mocked(getAllPaymentRepositories).mockResolvedValue([]);

    await usePaymentStore.getState().fetchPaymentConfig();

    expect(usePaymentStore.getState().paymentMethods).toHaveLength(0);
  });
});
