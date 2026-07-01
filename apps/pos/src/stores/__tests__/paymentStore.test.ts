import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '../paymentStore';
import { makePaymentMethod, makePaymentRepository } from '@/test/helpers';

// Mock API modules
vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({
    execute: vi.fn(),
    select: vi.fn().mockResolvedValue([]),
    close: vi.fn(),
  }),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/lib/offline/offlineCheckoutService', () => ({
  executeCheckout: vi.fn(),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'company-1',
      serverUrl: 'http://localhost',
      token: 'test-token',
      user: { id: 'user-1', name: 'Test User' },
      companies: [{ id: 'company-1', currency: 'EUR' }],
    }),
  },
}));

import { fetchPaymentMethods, fetchPaymentRepositories } from '@/api/paymentApi';

const mockCashMethod = makePaymentMethod();
const mockCashRegister = makePaymentRepository();

describe('paymentStore', () => {
  beforeEach(() => {
    usePaymentStore.getState().reset();
    vi.clearAllMocks();
  });

  it('fetches payment configuration', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);

    await usePaymentStore.getState().fetchPaymentConfig();

    const state = usePaymentStore.getState();
    expect(state.paymentMethods).toHaveLength(1);
    expect(state.paymentRepositories).toHaveLength(1);
  });

  it('throws when no cash payment method configured', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([mockCashRegister]);
    await usePaymentStore.getState().fetchPaymentConfig();

    await expect(
      usePaymentStore.getState().processCashCheckout('t-1', [], '50.00'),
    ).rejects.toThrow('No cash payment method configured');
  });

  it('throws when no cash register configured', async () => {
    vi.mocked(fetchPaymentMethods).mockResolvedValue([mockCashMethod]);
    vi.mocked(fetchPaymentRepositories).mockResolvedValue([]);
    await usePaymentStore.getState().fetchPaymentConfig();

    await expect(
      usePaymentStore.getState().processCashCheckout('t-1', [], '50.00'),
    ).rejects.toThrow('No cash register configured');
  });

});
