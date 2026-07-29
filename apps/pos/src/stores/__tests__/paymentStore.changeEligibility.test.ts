/**
 * Advanced (multi-tender) checkout — rounded due, change eligibility, tolerance
 * (spec 2026-07-27 §4.1, Task 7).
 *
 * Quick cash got the CheckoutPolicySnapshot in Task 6; the advanced path still
 * gated on the EXACT total and had no auto-accept branch at all. This suite
 * pins the four things that changes:
 *
 *   1. the hard tender gate compares against `snapshot.roundedTotal`;
 *   2. the cash-only predicate runs over the UNION of the payment lines AND
 *      the store's voucher tenders — a voucher leg is a payment leg and is
 *      never cash, so a voucher-partial sale is exact, never rounded;
 *   3. change can only ever come out of the drawer: a completion whose change
 *      exceeds the cash legs is refused BEFORE anything is signed;
 *   4. an in-tolerance all-cash shortfall is auto-accepted in FRONT of the
 *      manager-PIN path, which is otherwise untouched — and the evidence that
 *      path authors is denominated in the ROUNDED due.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  usePaymentStore,
  TenderToleranceApprovalRequiredError,
} from '@/stores/paymentStore';
import { usePaymentPolicyStore, type PaymentPolicy } from '@/stores/paymentPolicyStore';
import { useAuthStore } from '@/stores/authStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore, type Shift, type Terminal } from '@/stores/terminalStore';
import type { Operator } from '@/stores/operatorStore';
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
    idempotencyKey: 'idem-t7',
    localId: 'local-t7',
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

/** In-memory stand-in for the v64 `tolerance_auto_accepts` table (see Task 6). */
const persistedAccepts = new Map<string, number>();

vi.mock('@/lib/db/repositories/toleranceAutoAcceptRepository', () => ({
  getToleranceAutoAcceptCount: vi.fn(
    async (_db: unknown, shiftId: string) => persistedAccepts.get(shiftId) ?? 0,
  ),
  recordToleranceAutoAccept: vi.fn(async (_db: unknown, shiftId: string) => {
    const next = (persistedAccepts.get(shiftId) ?? 0) + 1;
    persistedAccepts.set(shiftId, next);
    return next;
  }),
}));

vi.mock('@/lib/operatorApproval/scopedManagerPin', () => ({
  verifyScopedManagerPin: vi.fn().mockResolvedValue({
    id: 'mgr-1',
    name: 'Manager Amel',
  }),
}));

vi.mock('@/lib/operatorApproval/posOverrideAuthoring', () => ({
  authorPosOverride: vi.fn().mockResolvedValue({
    approval_id: 'appr-1',
    approval_scope: 'tender_tolerance_override',
  }),
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

/**
 * Fully-typed fixtures — no `as never`. The cutover gate is keyed on
 * `fiscal_schema_version`, so a rename or a type change there MUST break this
 * file rather than leave it green against a stale shape.
 */
const TERMINAL: Terminal = {
  id: 'term-1',
  code: 'T001',
  name: 'Caisse 1',
  type: 'fixed',
  is_active: true,
  is_training_mode: false,
  fiscal_schema_version: 3,
  hardware_identifier: null,
  location: {
    id: 'loc1',
    name: 'Principale',
    code: 'MAIN',
    tax_id: null,
    vat_number: null,
    legal_identifiers: null,
  },
};

const SHIFT: Shift = {
  id: SHIFT_ID,
  terminal_id: 'term-1',
  shift_number: 1,
  status: 'OPEN',
  opening_cash: '0.000',
  opened_at: '2026-07-27T08:00:00Z',
  user: { id: 'user-1', name: 'Test Cashier' },
};

const OPERATOR: Operator = {
  id: 'op-1',
  name: 'Cashier Slim',
  email: 'slim@example.com',
  roles: [],
  permissions: [],
  can_discount: false,
  max_discount_percent: null,
};

function setTerminalState(): void {
  useTerminalStore.setState({
    terminal: TERMINAL,
    shift: SHIFT,
    hashChainReady: true,
  });
}

async function lastPolicySnapshot() {
  const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
  return vi.mocked(createOfflineReceipt).mock.calls[0]?.[1].policySnapshot;
}

describe('processAdvancedCheckout — change eligibility, rounded due, tolerance', () => {
  /**
   * Half of these cases assert a REFUSAL, and every refusal goes through the
   * store's `[POS][checkout][advanced] failed` console.error with a full stack.
   * That is correct production behaviour and useless test output, so it is
   * captured rather than printed — the assertions are on the thrown error and
   * the store state, never on the log.
   */
  let errorSpy: ReturnType<typeof vi.spyOn>;
  let warnSpy: ReturnType<typeof vi.spyOn>;

  afterEach(() => {
    errorSpy.mockRestore();
    warnSpy.mockRestore();
  });

  beforeEach(async () => {
    vi.clearAllMocks();
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    // `logToleranceDecline` warns on every refusal — expected here, and not
    // something any assertion in this file depends on.
    warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    persistedAccepts.clear();
    const { __resetTerminalLocksForTesting } = await import('@/lib/offline/terminalMutex');
    __resetTerminalLocksForTesting();
    usePaymentStore.getState().reset();
    usePaymentPolicyStore.getState().reset();
    setTndAuthState();
    useOperatorStore.setState({ operator: OPERATOR });
    setTerminalState();
    usePaymentStore.setState({
      paymentMethods: [
        makePaymentMethod({ id: 'pm-cash', code: 'CASH', is_cash_tender: true }),
        makePaymentMethod({
          id: 'pm-card', code: 'CARD', name: 'Card',
          is_physical: false, requires_third_party: true, is_cash_tender: false,
        }),
        makePaymentMethod({
          id: 'pm-voucher', code: 'store_voucher', name: 'Store Voucher',
          is_physical: false, is_cash_tender: false,
        }),
      ],
      paymentRepositories: [
        makePaymentRepository({ id: 'repo-cash', type: 'cash_register' }),
        makePaymentRepository({ id: 'repo-bank', code: 'BANK', name: 'Bank', type: 'bank_account' }),
        makePaymentRepository({ id: 'repo-virtual', code: 'VIRT', name: 'Virtual', type: 'virtual' }),
      ],
    });
  });

  it('refuses completion when the change owed exceeds the cash legs (card-only over-tender)', async () => {
    // 12.000 tendered on a 10.000 sale with NOTHING in the drawer to give the
    // 2.000 change back from. Pre-fix this signed a receipt.
    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '10.000' })],
        [{ payment_method_id: 'pm-card', amount: '12.000', repository_id: 'repo-bank' }],
      ),
    ).rejects.toThrow();
    expect(usePaymentStore.getState().error).toContain('Change cannot exceed');

    const { createOfflineReceipt } = await import('@/lib/offline/receiptService');
    expect(vi.mocked(createOfflineReceipt)).not.toHaveBeenCalled();
  });

  it('allows an EXACT card-only tender — zero change is not "change exceeding zero cash"', async () => {
    // The most common non-cash sale. Guards the comparison against a `>=`
    // typo, which would refuse every card payment on the terminal.
    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '10.000' })],
        [{ payment_method_id: 'pm-card', amount: '10.000', repository_id: 'repo-bank' }],
      ),
    ).resolves.toBeUndefined();
  });

  it('allows an over-tender whose change is fully covered by cash legs', async () => {
    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '10.000' })],
        [{ payment_method_id: 'pm-cash', amount: '12.000', repository_id: 'repo-cash' }],
      ),
    ).resolves.toBeUndefined();
  });

  it('allows a mixed over-tender whose change is covered by the cash leg alone', async () => {
    // 5.000 cash + 7.000 card on a 10.000 sale: 2.000 change against 5.000 in
    // the drawer. The predicate is the CASH sum, not the tendered sum.
    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '10.000' })],
        [
          { payment_method_id: 'pm-cash', amount: '5.000', repository_id: 'repo-cash' },
          { payment_method_id: 'pm-card', amount: '7.000', repository_id: 'repo-bank' },
        ],
      ),
    ).resolves.toBeUndefined();
  });

  it('does not round a voucher-partial tender (union rule)', async () => {
    // The modal merges voucher tenders into `payments`, so the voucher leg
    // arrives BOTH ways. Either way the union is not cash-only: 9.973 is
    // settled exactly, never rounded to 9.950.
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    usePaymentStore.getState().addVoucherPayment('V-1', '5.000');

    await usePaymentStore.getState().processAdvancedCheckout(
      'term-1',
      [makeCartItem({ id: 'i1', line_total: '9.973' })],
      [
        { payment_method_id: 'pm-cash', amount: '4.973', repository_id: 'repo-cash' },
        {
          payment_method_id: 'pm-voucher', amount: '5.000', repository_id: 'repo-virtual',
          instrument_type: 'store_voucher', instrument_serial: 'V-1',
        },
      ],
    );

    const snapshot = await lastPolicySnapshot();
    expect(snapshot?.cashOnly).toBe(false);
    expect(snapshot?.roundingApplied).toBe(false);
    expect(snapshot?.roundedTotal).toBe('9.973');
  });

  it('counts a voucher tender the caller did NOT merge into the payment lines', async () => {
    // Belt-and-braces on the union: even if a caller forgets to merge the
    // store's voucher tenders into `payments`, the cash-only predicate must
    // still see them. Without the union term these legs read as cash-only and
    // 9.973 would round to 9.950.
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    usePaymentStore.getState().addVoucherPayment('V-2', '5.000');

    await usePaymentStore.getState().processAdvancedCheckout(
      'term-1',
      [makeCartItem({ id: 'i1', line_total: '9.973' })],
      [{ payment_method_id: 'pm-cash', amount: '9.973', repository_id: 'repo-cash' }],
    );

    const snapshot = await lastPolicySnapshot();
    expect(snapshot?.cashOnly).toBe(false);
    expect(snapshot?.roundingApplied).toBe(false);
  });

  it('accepts a tender that covers the ROUNDED due but not the exact total', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '9.973' })],
        [{ payment_method_id: 'pm-cash', amount: '9.950', repository_id: 'repo-cash' }],
      ),
    ).resolves.toBeUndefined();

    const { verifyScopedManagerPin } = await import('@/lib/operatorApproval/scopedManagerPin');
    expect(vi.mocked(verifyScopedManagerPin)).not.toHaveBeenCalled();
    const snapshot = await lastPolicySnapshot();
    expect(snapshot?.roundedTotal).toBe('9.950');
    expect(snapshot?.adjustment).toBe('-0.023');
  });

  it('auto-accepts an in-tolerance all-cash shortfall without a PIN', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '9.973' })],
        [{ payment_method_id: 'pm-cash', amount: '9.900', repository_id: 'repo-cash' }],
      ),
    ).resolves.toBeUndefined();

    const { verifyScopedManagerPin } = await import('@/lib/operatorApproval/scopedManagerPin');
    expect(vi.mocked(verifyScopedManagerPin)).not.toHaveBeenCalled();
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(1);
    expect(persistedAccepts.get(SHIFT_ID)).toBe(1);
  });

  it('escalates to the PIN path once the durable per-shift budget is spent', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);
    persistedAccepts.set(SHIFT_ID, 10);

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '9.973' })],
        [{ payment_method_id: 'pm-cash', amount: '9.900', repository_id: 'repo-cash' }],
      ),
    ).rejects.toThrow(TenderToleranceApprovalRequiredError);
    expect(persistedAccepts.get(SHIFT_ID)).toBe(10);
  });

  it('never auto-accepts a shortfall on a MIXED tender', async () => {
    // 0.050 short, but part of it was settled by card — the drawer never sees
    // the write-off, so the cashier goes to the evidenced PIN path.
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '9.973' })],
        [
          { payment_method_id: 'pm-cash', amount: '4.923', repository_id: 'repo-cash' },
          { payment_method_id: 'pm-card', amount: '5.000', repository_id: 'repo-bank' },
        ],
      ),
    ).rejects.toThrow(TenderToleranceApprovalRequiredError);
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(0);
  });

  it('still demands a PIN for a beyond-tolerance shortfall', async () => {
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);

    await expect(
      usePaymentStore.getState().processAdvancedCheckout(
        'term-1',
        [makeCartItem({ id: 'i1', line_total: '9.973' })],
        [{ payment_method_id: 'pm-cash', amount: '9.000', repository_id: 'repo-cash' }],
      ),
    ).rejects.toThrow(TenderToleranceApprovalRequiredError);
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(0);
  });

  it('denominates the manager-PIN override evidence in the ROUNDED due', async () => {
    // The authored evidence must describe the amount actually due, otherwise
    // the shortfall on the override record disagrees with the receipt.
    usePaymentPolicyStore.getState().setPolicy(tndRoundingPolicy);

    await usePaymentStore.getState().processAdvancedCheckout(
      'term-1',
      [makeCartItem({ id: 'i1', line_total: '9.973' })],
      [{ payment_method_id: 'pm-cash', amount: '9.000', repository_id: 'repo-cash' }],
      undefined,
      undefined,
      undefined,
      { tenderTolerancePin: '1234' },
    );

    const { authorPosOverride } = await import('@/lib/operatorApproval/posOverrideAuthoring');
    const call = vi.mocked(authorPosOverride).mock.calls[0]?.[0];
    expect(call?.target.total_amount).toBe('9.950');
    expect(call?.target.shortfall_amount).toBe('0.950');
    expect(usePaymentStore.getState().toleranceAutoAcceptCount).toBe(0);
  });
});
