/**
 * Cash rounding + tender tolerance — the quick-cash path (spec 2026-07-27 §4.1).
 *
 * Pins the three things the CheckoutPolicySnapshot changes about quick cash:
 *   1. the cash method is selected by `is_cash_tender`, not by the legacy
 *      `is_physical && !has_maturity` shape (which classifies MEAL_VOUCHER as
 *      cash), and a device that has never pulled `/payment-methods` — every
 *      row still carrying migration v63's `DEFAULT 0` — gets the
 *      `errors.noCashMethod` banner rather than an obscure failure;
 *   2. the hard tender gate compares against the ROUNDED due;
 *   3. an in-tolerance shortfall is auto-accepted under a per-shift counter,
 *      and everything else still falls through to the unchanged manager-PIN
 *      path (which quick cash does not offer, so it refuses).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { usePaymentStore } from '@/stores/paymentStore';
import { usePaymentPolicyStore, type PaymentPolicy } from '@/stores/paymentPolicyStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { makeCartItem, makePaymentMethod, makePaymentRepository } from '@/test/helpers';

// ── Module-level mocks required by paymentStore ──────────────────────────────

vi.mock('@/api/receiptApi', () => ({
  fetchReceipt: vi.fn(),
}));

vi.mock('@/lib/offline/receiptService', () => ({
  createOfflineReceipt: vi.fn().mockResolvedValue({
    receiptNumber: 'MAIN-T001-2026-00000001',
    total: '9.950',
    subtotal: '9.950',
    taxAmount: '0.000',
    discountAmount: '0.000',
    changeDue: '0.000',
    idempotencyKey: 'idem-t6',
    localId: 'local-t6',
  }),
}));

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({ execute: vi.fn(), select: vi.fn() }),
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

// ── Fixtures ─────────────────────────────────────────────────────────────────

const SHIFT_ID = '019eb000-0000-7000-8000-000000000001';

const tndRoundingPolicy: PaymentPolicy = {
  cashRoundingEnabled: true,
  cashRoundingDenomination: '0.050',
  tenderToleranceEnabled: true,
  tenderTolerancePercentage: '0.0050',
  tenderToleranceMaxAmount: '0.100',
  currencyCode: 'TND',
  currencyScale: 3,
  refreshedAt: '2026-07-27 08:00:00',
};

function setTndAuthState(): void {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test Cashier',
      email: 'cashier@example.com',
      tenantId: 't-tnd',
      phone: null,
      status: 'active',
      locale: null,
      timezone: null,
      roles: [],
      permissions: [],
      emailVerified: true,
    },
    companyId: 'company-tnd',
    companies: [
      {
        id: 'company-tnd',
        name: 'Pharmacie Tunis',
        legalName: 'Pharmacie Tunis SARL',
        tax_id: 'TN0001234',
        countryCode: 'TN',
        address_street: '1 Avenue Habib Bourguiba',
        address_city: 'Tunis',
        address_postal_code: '1000',
        currency: 'TND',
        locale: 'fr',
        timezone: 'Africa/Tunis',
      },
    ],
    token: 'tok',
    serverUrl: 'http://localhost',
    isAuthenticated: true,
    isLoading: false,
    isInitialized: true,
  });
}

function setTerminalState(overrides: { fiscalSchemaVersion?: 2 | 3; shiftId?: string } = {}): void {
  useTerminalStore.setState({
    terminal: {
      id: 'term-1',
      code: 'T001',
      name: 'Caisse 1',
      type: 'fixed',
      is_active: true,
      is_training_mode: false,
      fiscal_schema_version: overrides.fiscalSchemaVersion ?? 3,
      hardware_identifier: null,
      location: { id: 'loc1', name: 'Principale', code: 'MAIN' },
    },
    shift: {
      id: overrides.shiftId ?? SHIFT_ID,
      terminal_id: 'term-1',
      shift_number: 1,
      status: 'OPEN',
      opening_cash: '0.000',
      opened_at: '2026-07-27T08:00:00Z',
      user: { id: 'user-1', name: 'Test Cashier' },
    },
    hashChainReady: true,
  } as never);
}

function setCashMethodState(): void {
  usePaymentStore.setState({
    paymentMethods: [makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true })],
    paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
  });
}

describe('processCashCheckout — is_cash_tender selection + rounded gate', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    const { __resetTerminalLocksForTesting } = await import('@/lib/offline/terminalMutex');
    __resetTerminalLocksForTesting();
    usePaymentStore.getState().reset();
    usePaymentPolicyStore.getState().reset();
    setTndAuthState();
    useOperatorStore.setState({
      operator: { id: 'op-1', name: 'Cashier Slim', email: 'slim@example.com', roles: [] },
    } as never);
    setTerminalState();
  });

  it('selects the cash method by is_cash_tender, not by is_physical/has_maturity', async () => {
    usePaymentStore.setState({
      paymentMethods: [
        makePaymentMethod({
          id: 'pm-meal', code: 'MEAL_VOUCHER',
          is_physical: true, has_maturity: false, is_cash_tender: false, position: 0,
        }),
        makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true, position: 1 }),
      ],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await usePaymentStore.getState().processCashCheckout(
      'term-1', [makeCartItem({ id: 'i1', line_total: '10.000' })], '10.000',
    );

    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    expect(vi.mocked(createOfflineReceipt).mock.calls[0]?.[1].payments[0]?.methodCode)
      .toBe('CASH');
  });

  it('throws errors.noCashMethod when no method carries is_cash_tender', async () => {
    // The migration-v63 upgrade window: `is_cash_tender` lands with DEFAULT 0
    // and no backfill, so a device that never pulls /payment-methods sees no
    // cash method at all. Fail-closed — but with the configured banner.
    usePaymentStore.setState({
      paymentMethods: [makePaymentMethod({ id: 'pm-meal', code: 'MEAL_VOUCHER', is_cash_tender: false })],
      paymentRepositories: [makePaymentRepository({ id: 'repo-cash', type: 'cash_register' })],
    });

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '10.000' })], '10.000',
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().error).toContain('cash payment method');
  });

  it('accepts a tender that covers the ROUNDED total but not the exact total', async () => {
    // exact 9.973 -> rounded 9.950 on a v3 terminal; tender 9.950 is now
    // sufficient though it is 0.023 short of the exact total.
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    setCashMethodState();

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.950',
      ),
    ).resolves.toBeUndefined();
  });

  it('refuses on a fiscal_schema_version 2 terminal what a v3 terminal rounds (cutover gate)', async () => {
    // 9.900 against exact 9.973:
    //   v3 -> rounded 9.950, shortfall 0.050, inside the D floor -> accepted;
    //   v2 -> NOT rounded, shortfall 0.073 against the pct cap 0.049 -> refused.
    // A non-cutover terminal must never have its NF525 grand totals moved.
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    setTerminalState({ fiscalSchemaVersion: 2 });
    setCashMethodState();

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.900',
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(0);
  });

  it('auto-accepts an in-tolerance shortfall and increments the per-shift counter', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    setCashMethodState();

    await usePaymentStore.getState().processCashCheckout(
      'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.900',
    );

    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(1);
    expect(usePaymentStore.getState().toleranceAutoAcceptShiftId).toBe(SHIFT_ID);
  });

  it('still refuses a beyond-tolerance shortfall from quick cash', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    setCashMethodState();

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.500',
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(0);
  });

  it('refuses an in-tolerance shortfall when tender tolerance is DISABLED', async () => {
    // The denomination floor still reports D of headroom while rounding is on;
    // only `tenderToleranceEnabled` stops it from being spent. Without this
    // check the disable switch would be inert.
    usePaymentPolicyStore.getState().setPolicy({
      ...tndRoundingPolicy,
      tenderToleranceEnabled: false,
    });
    setCashMethodState();

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.900',
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(0);
  });

  it('does not increment the counter for a fully-covered tender', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    setCashMethodState();

    await usePaymentStore.getState().processCashCheckout(
      'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '10.000',
    );

    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(0);
  });

  it('escalates to the PIN path once the per-shift auto-accept limit is spent', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    setCashMethodState();
    usePaymentStore.setState({
      toleranceAutoAcceptShiftId: SHIFT_ID,
      toleranceAutoAcceptCount: 10,
    });

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.900',
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(10);
  });

  it('gives a NEW shift a fresh auto-accept budget', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    setCashMethodState();
    // Budget spent — but on a DIFFERENT (now closed) shift.
    usePaymentStore.setState({
      toleranceAutoAcceptShiftId: '019eb000-0000-7000-8000-0000000000ff',
      toleranceAutoAcceptCount: 10,
    });

    await usePaymentStore.getState().processCashCheckout(
      'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.900',
    );

    expect(usePaymentStore.getState().toleranceAutoAcceptShiftId).toBe(SHIFT_ID);
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(1);
  });

  it('does not round when no policy has been pulled (exact today-behavior)', async () => {
    setCashMethodState();

    await expect(
      usePaymentStore.getState().processCashCheckout(
        'term-1', [makeCartItem({ id: 'i1', line_total: '9.973' })], '9.950',
      ),
    ).rejects.toThrow();
  });
});
