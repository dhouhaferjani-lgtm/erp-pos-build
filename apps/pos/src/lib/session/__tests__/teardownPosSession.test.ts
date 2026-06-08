import { describe, it, expect, vi, beforeEach } from 'vitest';

const clearCart = vi.fn(); const clearAll = vi.fn(); const clearDraftState = vi.fn();
const clearVoucherTenders = vi.fn(); const paymentReset = vi.fn(); const productReset = vi.fn();
const clearOperator = vi.fn();

vi.mock('@/stores/cartStore', () => ({ useCartStore: { getState: () => ({ clearCart }) } }));
vi.mock('@/stores/refundFlowStore', () => ({ useRefundFlowStore: { getState: () => ({ clearAll }) } }));
vi.mock('@/stores/refundDraftStore', () => ({ useRefundDraftStore: { getState: () => ({ clearDraftState }) } }));
vi.mock('@/stores/paymentStore', () => ({ usePaymentStore: { getState: () => ({ clearVoucherTenders, reset: paymentReset }) } }));
vi.mock('@/stores/productStore', () => ({ useProductStore: { getState: () => ({ reset: productReset }) } }));
vi.mock('@/stores/operatorStore', () => ({ useOperatorStore: { getState: () => ({ clearOperator }) } }));

import { teardownPosSessionStores } from '../teardownPosSession';

describe('teardownPosSessionStores', () => {
  beforeEach(() => vi.clearAllMocks());
  it('clears each session store exactly once', () => {
    teardownPosSessionStores();
    for (const fn of [clearCart, clearAll, clearDraftState, clearVoucherTenders, paymentReset, productReset, clearOperator]) {
      expect(fn).toHaveBeenCalledTimes(1);
    }
  });
});
