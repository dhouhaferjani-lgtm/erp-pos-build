import { describe, it, expect, beforeEach, vi } from 'vitest';
import { usePaymentStore } from '@/stores/paymentStore';
import { useCartStore } from '@/stores/cartStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore, type Terminal, type Shift } from '@/stores/terminalStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

/**
 * 2026-06-12 live failure: fiscal canonical payloads validate shift_id as a
 * lowercase-hex UUID, but checkout passed raw `shift.id` — `offline-<uuid>`
 * for offline-opened shifts — so every sale on an offline shift was rejected
 * with payload_field_invalid. The payload must carry the device-minted
 * fiscal shift id (the same id the SESSION_OPEN event was authored with).
 */

vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn(),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn(async () => ({
    localId: 'receipt-local-1',
    receiptNumber: 'MAIN-T001-2026-00000001',
    total: '50.00',
    subtotal: '50.00',
    taxAmount: '0.00',
    discountAmount: '0.00',
    changeDue: 0,
    fiscalHash: 'mock-hash',
    idempotencyKey: 'idem-1',
  })),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn(async () => ({ execute: vi.fn(), select: vi.fn() })),
}));

vi.mock('@/api/paymentApi', () => ({
  fetchPaymentMethods: vi.fn(),
  fetchPaymentRepositories: vi.fn(),
}));

vi.mock('@/lib/db/repositories/paymentRepository', () => ({
  getAllPaymentMethods: vi.fn().mockResolvedValue([]),
  getAllPaymentRepositories: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: {
    getState: vi.fn().mockReturnValue({
      scheduler: null,
      pendingReceiptCount: 0,
      setPendingCount: vi.fn(),
      triggerSync: vi.fn(),
    }),
  },
}));

const terminal = {
  id: 'term-1',
  code: 'T001',
  name: 'Counter 1',
  type: 'fixed',
  is_active: true,
  fiscal_schema_version: 3,
  is_training_mode: false,
  hardware_identifier: null,
  location: {
    id: 'loc1',
    name: 'Main',
    code: 'MAIN',
    tax_id: null,
    vat_number: null,
    legal_identifiers: null,
  },
} satisfies Terminal;

function shiftState(overrides: Partial<Shift>): Shift {
  return {
    id: 'shift-1',
    terminal_id: 'term-1',
    shift_number: 1,
    status: 'OPEN',
    opening_cash: '0.00',
    opened_at: '2026-06-12T08:00:00Z',
    user: { id: 'user-1', name: 'Houssem' },
    ...overrides,
  };
}

describe('paymentStore fiscal shift id in SALE_RECEIPT payloads', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    const { __resetTerminalLocksForTesting } = await import('@/lib/offline/terminalMutex');
    __resetTerminalLocksForTesting();

    usePaymentStore.getState().reset();
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Houssem',
        email: 'h@example.com',
        tenantId: 't1',
        phone: null,
        status: 'active',
        locale: null,
        timezone: null,
        roles: [],
        permissions: [],
        emailVerified: true,
      },
      companyId: 'company-1',
      companies: [{
        id: 'company-1',
        name: 'Test Co',
        legalName: 'Test SA',
        tax_id: 'COMPANY-FR-TAX',
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
        id: 'op-1',
        name: 'Cashier Alice',
        email: 'a@x.com',
        roles: [],
        permissions: [],
        can_discount: false,
        max_discount_percent: null,
      },
      isLocked: false,
      lastActivity: Date.now(),
      hasPins: true,
    });
    useTerminalStore.setState({
      terminal,
      shift: shiftState({}),
      hashChainReady: true,
    });
    useCartStore.setState({
      items: [makeCartItem({ line_total: '50.00', tax_amount: '0.00' })],
    });
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH' })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });
  });

  it('stamps the device-minted fiscal_shift_id, not the raw offline- shift id', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    useTerminalStore.setState({
      shift: shiftState({
        id: 'offline-44444444-4444-4444-8444-444444444444',
        fiscal_shift_id: '11111111-1111-4111-8111-111111111111',
        fiscal_session_id: '22222222-2222-4222-8222-222222222222',
      }),
    });

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 50);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        shiftId: '11111111-1111-4111-8111-111111111111',
      }),
    );
  });

  it('derives the UUID from an offline- prefixed shift id when no fiscal_shift_id exists (legacy shift)', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    useTerminalStore.setState({
      shift: shiftState({ id: 'offline-44444444-4444-4444-8444-444444444444' }),
    });

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 50);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        shiftId: '44444444-4444-4444-8444-444444444444',
      }),
    );
  });

  it('keeps a plain server shift UUID as-is', async () => {
    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    useTerminalStore.setState({
      shift: shiftState({ id: '33333333-3333-4333-8333-333333333333' }),
    });

    await usePaymentStore.getState().processCashCheckout('term-1', useCartStore.getState().items, 50);

    expect(createOfflineReceipt).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        shiftId: '33333333-3333-4333-8333-333333333333',
      }),
    );
  });
});
