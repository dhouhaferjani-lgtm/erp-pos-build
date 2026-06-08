import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { POSProduct } from '@/types/product';

// ── Mock the audit emit so we assert the enqueued shape without touching SQLite ──
const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({} as import('@tauri-apps/plugin-sql').default),
}));

const insertHeldTransaction = vi.fn().mockResolvedValue(undefined);
const deleteHeldTransaction = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/db/repositories/heldTransactionRepository', () => ({
  insertHeldTransaction: (...a: unknown[]) => insertHeldTransaction(...a),
  listHeldTransactions: vi.fn().mockResolvedValue([]),
  deleteHeldTransaction: (...a: unknown[]) => deleteHeldTransaction(...a),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: { getState: () => ({ companyId: 'co-1', companies: [{ id: 'co-1', currency: 'EUR' }] }) },
}));
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: { getState: () => ({ terminal: { id: 'term-1' } }) },
}));

// Operator is mutable across tests (cross-operator recall).
let currentOperatorId: string | null = 'op-1';
vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: { getState: () => ({ operator: currentOperatorId ? { id: currentOperatorId } : null }) },
}));

// Stub the payment store side-effect called inside holdCurrentCart.
vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: { getState: () => ({ discardPendingSubmission: vi.fn() }) },
}));

import { useHoldStore, type HeldTransaction } from '../holdStore';
import { useCartStore } from '../cartStore';

function makeProduct(overrides: Partial<POSProduct> = {}): POSProduct {
  return { id: 'prod-1', name: 'Test Product', sku: 'SKU-001', sale_price: '10.00', stock_quantity: 50, ...overrides };
}

function lastCallOfType(type: string): Record<string, unknown> | undefined {
  for (let i = recordAuditEvent.mock.calls.length - 1; i >= 0; i--) {
    const arg = recordAuditEvent.mock.calls[i]![0] as Record<string, unknown>;
    if (arg.type === type) return arg;
  }
  return undefined;
}

function makeHeld(overrides: Partial<HeldTransaction> = {}): HeldTransaction {
  return {
    id: 'held-1',
    label: 'Table 3',
    items: [],
    subtotal: 16.1,
    total: 16.1,
    itemCount: 2,
    heldAt: new Date(Date.now() - 5000).toISOString(),
    heldByOperatorId: 'op-1',
    ...overrides,
  };
}

describe('holdStore — audit emits', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    currentOperatorId = 'op-1';
    useCartStore.getState().clearCart();
    useHoldStore.setState({ heldTransactions: [], isLoading: false, error: null });
  });

  describe('pos.sale_held (holdCurrentCart)', () => {
    it('emits with line_count, total, discount_total and the pre-clear cart_session_id', async () => {
      useCartStore.getState().addItem(makeProduct({ sale_price: '9.80' }));
      useCartStore.getState().addItem(makeProduct({ id: 'p2', sale_price: '6.30' }));
      const sessionId = useCartStore.getState().getCartSessionId();
      expect(sessionId).not.toBeNull();

      await useHoldStore.getState().holdCurrentCart('Table 3');

      const call = lastCallOfType('pos.sale_held')!;
      expect(call).toBeDefined();
      expect(call.aggregateType).toBe('HeldSale');
      const payload = call.payload as Record<string, unknown>;
      expect(payload.line_count).toBe(2);
      expect(payload.total).toBeCloseTo(16.1);
      expect(payload.discount_total).toBe(0);
      // Correlates with the cart session that produced the held sale.
      expect(payload.cart_session_id).toBe(sessionId);
      // aggregate id is the new held id, not the cart session id.
      expect(call.aggregateId).not.toBe(sessionId);
    });

    it('does not emit when the cart is empty', async () => {
      await useHoldStore.getState().holdCurrentCart('Empty');
      expect(lastCallOfType('pos.sale_held')).toBeUndefined();
    });

    it('an emit failure does not break holdCurrentCart', async () => {
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      useCartStore.getState().addItem(makeProduct());
      await expect(useHoldStore.getState().holdCurrentCart('T')).resolves.toBeUndefined();
      expect(insertHeldTransaction).toHaveBeenCalledTimes(1);
      // cart was still cleared
      expect(useCartStore.getState().items).toHaveLength(0);
    });
  });

  describe('pos.sale_recalled (recallTransaction)', () => {
    it('emits held_duration_ms, held_by_operator_id, cross_operator, cross_shift', async () => {
      const held = makeHeld({ heldByOperatorId: 'op-1', heldAt: new Date(Date.now() - 5000).toISOString() });
      useHoldStore.setState({ heldTransactions: [held] });

      await useHoldStore.getState().recallTransaction('held-1');

      const call = lastCallOfType('pos.sale_recalled')!;
      expect(call.aggregateType).toBe('HeldSale');
      expect(call.aggregateId).toBe('held-1');
      const payload = call.payload as Record<string, unknown>;
      expect(payload.held_by_operator_id).toBe('op-1');
      expect(payload.held_duration_ms).toBeGreaterThanOrEqual(5000);
      expect(payload.cross_operator).toBe(false);
      // No shift recorded on held rows — null by design.
      expect(payload.cross_shift).toBeNull();
    });

    it('sets cross_operator=true when recalled by a different operator', async () => {
      useHoldStore.setState({ heldTransactions: [makeHeld({ heldByOperatorId: 'op-1' })] });
      currentOperatorId = 'op-2';

      await useHoldStore.getState().recallTransaction('held-1');

      const payload = lastCallOfType('pos.sale_recalled')!.payload as Record<string, unknown>;
      expect(payload.cross_operator).toBe(true);
    });

    it('does not emit when the held id is unknown', async () => {
      await useHoldStore.getState().recallTransaction('missing');
      expect(lastCallOfType('pos.sale_recalled')).toBeUndefined();
    });

    it('an emit failure does not break recallTransaction', async () => {
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      useHoldStore.setState({ heldTransactions: [makeHeld()] });
      await expect(useHoldStore.getState().recallTransaction('held-1')).resolves.toBeDefined();
      expect(deleteHeldTransaction).toHaveBeenCalledTimes(1);
    });
  });

  describe('pos.sale_hold_discarded (discardTransaction)', () => {
    it('emits held_duration_ms', async () => {
      useHoldStore.setState({
        heldTransactions: [makeHeld({ heldAt: new Date(Date.now() - 3000).toISOString() })],
      });

      await useHoldStore.getState().discardTransaction('held-1');

      const call = lastCallOfType('pos.sale_hold_discarded')!;
      expect(call.aggregateType).toBe('HeldSale');
      expect(call.aggregateId).toBe('held-1');
      const payload = call.payload as Record<string, unknown>;
      expect(payload.held_duration_ms).toBeGreaterThanOrEqual(3000);
    });

    it('does not emit when the held id is unknown', async () => {
      await useHoldStore.getState().discardTransaction('missing');
      expect(lastCallOfType('pos.sale_hold_discarded')).toBeUndefined();
    });

    it('an emit failure does not break discardTransaction', async () => {
      recordAuditEvent.mockRejectedValueOnce(new Error('audit down'));
      useHoldStore.setState({ heldTransactions: [makeHeld()] });
      await expect(useHoldStore.getState().discardTransaction('held-1')).resolves.toBeUndefined();
      expect(deleteHeldTransaction).toHaveBeenCalledTimes(1);
    });
  });
});
