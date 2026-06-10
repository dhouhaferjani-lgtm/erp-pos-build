/**
 * Phase 3 — settled-print orchestration tests.
 *
 * Mocks at the Tauri print-command boundary (`@/lib/printing`'s printReceipt /
 * printVoucherTicket wrappers) following the printing-lib test pattern. The
 * builders run REAL so the asserted payloads are the actual mapped data.
 * The receipt-data builder (`buildEscPosRefundReceiptData`) is mocked in the
 * "builder throws" test only — every other test exercises it end-to-end.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('i18next', () => ({
  default: {
    t: (key: string) => key,
  },
}));

// Print-command boundary: the Tauri invoke wrappers are mocked; everything
// above them (builders, orchestration) is real.
vi.mock('@/lib/printing', () => ({
  printReceipt: vi.fn(),
  printVoucherTicket: vi.fn(),
  getPrintSettingsFromStore: vi.fn(() => ({
    columns: 42,
    cut_mode: 'partial',
    encoding: 'cp1252',
    footer_text: '',
    copies: 1,
  })),
  isTauriEnvironment: vi.fn(() => true),
}));

// The receipt-data builder is mocked only to simulate a throw (Fix 1). All
// other tests use the real builder (the mock is reset in beforeEach).
vi.mock('@/lib/buildReceiptData', async () => {
  const actual = await vi.importActual<typeof import('@/lib/buildReceiptData')>(
    '@/lib/buildReceiptData',
  );
  return {
    ...actual,
    buildEscPosRefundReceiptData: vi.fn((...args) =>
      // Default: delegate to the real implementation so other tests are unaffected.
      (actual.buildEscPosRefundReceiptData as (...a: unknown[]) => unknown)(...args),
    ),
  };
});

vi.mock('@/stores/printerStore', () => ({
  usePrinterStore: {
    getState: vi.fn(),
  },
}));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn(),
  },
}));
vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: {
    getState: vi.fn(),
  },
}));
vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: {
    getState: vi.fn(),
  },
}));

import {
  printReceipt,
  printVoucherTicket,
  isTauriEnvironment,
} from '@/lib/printing';
import type { PrinterConfig } from '@/lib/printing';
import { buildEscPosRefundReceiptData } from '@/lib/buildReceiptData';
import { usePrinterStore } from '@/stores/printerStore';
import { useAuthStore } from '@/stores/authStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useOperatorStore } from '@/stores/operatorStore';
import { printRefundSettlementArtifacts } from '../refundReceiptPrinting';
import type {
  IssuedVoucher,
  ReturnSettlementResponse,
} from '../refundSettlementService';

const printerConfig: PrinterConfig = {
  connection_type: 'network',
  address: '192.168.1.50:9100',
  name: 'Kitchen TM-T20',
};

function makeVoucher(): IssuedVoucher {
  return {
    id: 'voucher-uuid-1',
    code: 'VCH-ABC-123',
    initial_balance: '20.00',
    currency: 'EUR',
    expires_at: '2026-12-31T23:59:59Z',
    redemption_mode: 'bearer',
    partner_id: null,
  };
}

function makeResponse(
  overrides: Partial<ReturnSettlementResponse> = {},
): ReturnSettlementResponse {
  return {
    id: 'c0000000-0000-4000-8000-000000000001',
    receipt_number: 'L01-T01-00043',
    receipt_type: 'return',
    original_receipt_id: 'b0000000-0000-4000-8000-000000000001',
    return_reason: 'defective',
    subtotal: '-16.81',
    tax_amount: '-3.19',
    total: '-20.00',
    currency: 'EUR',
    posted_at: '2026-06-10T09:00:00Z',
    qr_token: 'v:kid:c0000000-0000-4000-8000-000000000001:mac',
    issued_voucher: null,
    lines: [
      {
        product_name: 'Widget A',
        quantity: '-2.0000',
        unit_price: '10.0000',
        line_total: '-20.0000',
      },
    ],
    ...overrides,
  };
}

function input(overrides: Partial<Parameters<typeof printRefundSettlementArtifacts>[0]> = {}) {
  return {
    response: makeResponse(),
    originalReceiptNumber: 'L01-T01-00042',
    originalReceiptQrToken: 'v:kid:original-uuid:mac',
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(isTauriEnvironment).mockReturnValue(true);
  vi.mocked(usePrinterStore.getState).mockReturnValue({
    printerConfig,
  } as unknown as ReturnType<typeof usePrinterStore.getState>);
  vi.mocked(useAuthStore.getState).mockReturnValue({
    companyId: 'company-1',
    companies: [{ id: 'company-1', name: 'Acme Shop', countryCode: 'FR' }],
  } as unknown as ReturnType<typeof useAuthStore.getState>);
  vi.mocked(useTerminalStore.getState).mockReturnValue({
    terminal: { id: 'terminal-1', name: 'T1' },
  } as unknown as ReturnType<typeof useTerminalStore.getState>);
  vi.mocked(useOperatorStore.getState).mockReturnValue({
    operator: { id: 'op-1', name: 'Alice' },
  } as unknown as ReturnType<typeof useOperatorStore.getState>);
  vi.mocked(printReceipt).mockResolvedValue(undefined);
  vi.mocked(printVoucherTicket).mockResolvedValue(undefined);
});

describe('printRefundSettlementArtifacts', () => {
  it('prints the AVOIR with the mapped refund receipt data', async () => {
    const outcome = await printRefundSettlementArtifacts(input());

    expect(outcome).toEqual({ status: 'printed', tickets: 1 });
    expect(printReceipt).toHaveBeenCalledTimes(1);
    const [receiptData, usedPrinter] = vi.mocked(printReceipt).mock.calls[0]!;
    expect(usedPrinter).toBe(printerConfig);
    expect(receiptData.receipt_kind).toBe('refund');
    expect(receiptData.receipt_number).toBe('L01-T01-00043');
    expect(receiptData.original_receipt_number).toBe('L01-T01-00042');
    expect(receiptData.original_receipt_qr_token).toBe('v:kid:original-uuid:mac');
    expect(receiptData.total).toBe('-20.00');
    expect(receiptData.company.name).toBe('Acme Shop');
    expect(receiptData.terminal_name).toBe('T1');
    expect(receiptData.operator_name).toBe('Alice');
    // No voucher in the response → no voucher ticket.
    expect(printVoucherTicket).not.toHaveBeenCalled();
  });

  it('prints the voucher ticket AFTER the AVOIR when the response carries issued_voucher', async () => {
    const order: string[] = [];
    vi.mocked(printReceipt).mockImplementation(async () => {
      order.push('avoir');
    });
    vi.mocked(printVoucherTicket).mockImplementation(async () => {
      order.push('voucher');
    });

    const outcome = await printRefundSettlementArtifacts(
      input({ response: makeResponse({ issued_voucher: makeVoucher() }) }),
    );

    expect(outcome).toEqual({ status: 'printed', tickets: 2 });
    expect(order).toEqual(['avoir', 'voucher']);
    const [ticket] = vi.mocked(printVoucherTicket).mock.calls[0]!;
    expect(ticket.code).toBe('VCH-ABC-123');
    expect(ticket.initial_balance).toBe('20.00');
    expect(ticket.expires_at).toBe('2026-12-31T23:59:59Z');
    expect(ticket.redemption_mode).toBe('Bearer');
    expect(ticket.issued_at).toBe('2026-06-10T09:00:00Z');
  });

  it('returns a typed failure (never throws) when the AVOIR print fails — the refund stays settled', async () => {
    const printError = new Error('printer offline');
    vi.mocked(printReceipt).mockRejectedValue(printError);

    const outcome = await printRefundSettlementArtifacts(
      input({ response: makeResponse({ issued_voucher: makeVoucher() }) }),
    );

    expect(outcome).toEqual({ status: 'failed', error: printError });
    // Fails before reaching the voucher ticket (no half-ordered tape).
    expect(printVoucherTicket).not.toHaveBeenCalled();
  });

  it('returns a typed failure when the voucher ticket print fails after the AVOIR printed', async () => {
    const printError = new Error('paper out');
    vi.mocked(printVoucherTicket).mockRejectedValue(printError);

    const outcome = await printRefundSettlementArtifacts(
      input({ response: makeResponse({ issued_voucher: makeVoucher() }) }),
    );

    expect(outcome).toEqual({ status: 'failed', error: printError });
    expect(printReceipt).toHaveBeenCalledTimes(1);
  });

  it('skips silently outside the Tauri environment (browser POS cannot print)', async () => {
    vi.mocked(isTauriEnvironment).mockReturnValue(false);

    const outcome = await printRefundSettlementArtifacts(input());

    expect(outcome).toEqual({ status: 'skipped', reason: 'not_tauri' });
    expect(printReceipt).not.toHaveBeenCalled();
  });

  it('skips silently when no printer is configured (mirrors the sale path canEscPosPrint gate)', async () => {
    vi.mocked(usePrinterStore.getState).mockReturnValue({
      printerConfig: null,
    } as unknown as ReturnType<typeof usePrinterStore.getState>);

    const outcome = await printRefundSettlementArtifacts(input());

    expect(outcome).toEqual({ status: 'skipped', reason: 'no_printer' });
    expect(printReceipt).not.toHaveBeenCalled();
    expect(printVoucherTicket).not.toHaveBeenCalled();
  });

  it('returns a typed failure (never throws) when the builder throws before print — unhandled rejection hole closed', async () => {
    // Simulate Big() / monetary parse blowing up on malformed data.
    const builderError = new RangeError('[big.js] Invalid number');
    vi.mocked(buildEscPosRefundReceiptData).mockImplementationOnce(() => {
      throw builderError;
    });

    const outcome = await printRefundSettlementArtifacts(input());

    // Must return {status:'failed'}, NOT an unhandled rejection.
    expect(outcome).toEqual({ status: 'failed', error: builderError });
    // The printer must never be reached if the builder threw.
    expect(printReceipt).not.toHaveBeenCalled();
    expect(printVoucherTicket).not.toHaveBeenCalled();
  });
});
