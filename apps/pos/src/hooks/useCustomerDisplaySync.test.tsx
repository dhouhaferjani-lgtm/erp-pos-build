import { cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { CartItem } from '@/types/cart';
import { sendCartUpdate } from '@/lib/customerDisplay';
import { useAuthStore } from '@/stores/authStore';
import { useCartStore } from '@/stores/cartStore';
import { useCustomerDisplayStore } from '@/stores/customerDisplayStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useCustomerDisplaySync } from './useCustomerDisplaySync';

vi.mock('@/lib/customerDisplay', () => ({
  sendCartUpdate: vi.fn().mockResolvedValue(undefined),
  sendIdleScreen: vi.fn().mockResolvedValue(undefined),
  sendThankYou: vi.fn().mockResolvedValue(undefined),
}));

const fractionalItem: CartItem = {
  id: 'line-1',
  product: {
    id: 'product-1',
    name: 'Olive oil',
    sku: 'OIL-001',
    price: '8.00',
    quantity_decimals: 2,
  },
  quantity: 1.5,
  unit_price: '8.00',
  line_total: '12.00',
  tax_rate: '0',
  tax_amount: '0.00',
};

describe('useCustomerDisplaySync', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useCustomerDisplayStore.setState({ isOpen: true, idleImagePath: '' });
    useCartStore.setState({ items: [fractionalItem] });
    usePaymentStore.setState({ lastReceipt: null });
    useAuthStore.setState({
      companyId: 'company-1',
      companies: [{
        id: 'company-1',
        name: 'Demo company',
        legalName: 'Demo company',
        countryCode: 'FR',
        currency: 'EUR',
        locale: 'fr-FR',
        timezone: 'Europe/Paris',
      }],
    });
  });

  afterEach(() => {
    cleanup();
    useCustomerDisplayStore.setState({ isOpen: false });
    useCartStore.setState({ items: [] });
    useAuthStore.setState({ companyId: null, companies: [] });
  });

  it('sends fractional quantities with the product precision to the customer display', async () => {
    renderHook(() => useCustomerDisplaySync());

    await waitFor(() => {
      expect(sendCartUpdate).toHaveBeenCalledWith(
        [{
          name: 'Olive oil',
          quantity: 1.5,
          quantity_decimals: 2,
          line_total: '12.00',
        }],
        '12.00',
        'EUR',
      );
    });
  });
});
