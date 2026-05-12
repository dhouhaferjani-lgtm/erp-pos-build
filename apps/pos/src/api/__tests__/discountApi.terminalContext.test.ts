import { describe, expect, it, vi } from 'vitest';
import { fetchDiscountPermissions } from '@/api/discountApi';
import { apiGet } from '@/lib/api';

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
}));

describe('fetchDiscountPermissions', () => {
  it('sends terminal context and returns the unwrapped API payload', async () => {
    vi.mocked(apiGet).mockResolvedValueOnce({
      canDiscount: true,
      userCanDiscount: true,
      userMaxDiscountPercent: null,
      terminalAllowsDiscounts: true,
      canApplyLineDiscounts: true,
      canApplyTransactionDiscounts: true,
      maxDiscountPercent: 10,
      requiresReason: false,
    });

    await expect(fetchDiscountPermissions('POS01', 'op-1')).resolves.toEqual({
      canDiscount: true,
      userCanDiscount: true,
      userMaxDiscountPercent: null,
      terminalAllowsDiscounts: true,
      canApplyLineDiscounts: true,
      canApplyTransactionDiscounts: true,
      maxDiscountPercent: 10,
      requiresReason: false,
    });

    expect(apiGet).toHaveBeenCalledWith('/pos/discount-permissions', {
      terminal_code: 'POS01',
      operator_id: 'op-1',
    });
  });
});
