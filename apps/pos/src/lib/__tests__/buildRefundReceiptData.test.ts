import { describe, it, expect, vi } from 'vitest';

// buildEscPosRefundReceiptData uses i18next for the localized labels — stub it
// so the builder runs in isolation. Mirrors buildVoucherTicketData.test.ts.
vi.mock('i18next', () => ({
  default: {
    t: (key: string) => key,
  },
}));

// decimal.ts -> currency.ts -> useAuthStore reaches the auth store at module
// load time; provide an empty stub.
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import {
  buildEscPosRefundReceiptData,
  buildRefundVoucherTicketData,
} from '../buildReceiptData';
import type { RefundReceiptPrintContext } from '../buildReceiptData';
import type {
  IssuedVoucher,
  ReturnSettlementResponse,
} from '@/lib/refundFlow/refundSettlementService';

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

function makeContext(
  overrides: Partial<RefundReceiptPrintContext> = {},
): RefundReceiptPrintContext {
  return {
    companyName: 'Acme Shop',
    companyCountryCode: 'FR',
    terminalName: 'T1',
    operatorName: 'Alice',
    originalReceiptNumber: 'L01-T01-00042',
    originalReceiptQrToken: 'v:kid:original-uuid:mac',
    ...overrides,
  };
}

describe('buildEscPosRefundReceiptData — Phase 3 AVOIR mapping', () => {
  it('marks the payload as a refund receipt with the original ticket reference', () => {
    const data = buildEscPosRefundReceiptData(makeResponse(), makeContext());

    expect(data.receipt_kind).toBe('refund');
    expect(data.original_receipt_number).toBe('L01-T01-00042');
    expect(data.original_receipt_qr_token).toBe('v:kid:original-uuid:mac');
  });

  it('builds from the SETTLEMENT RESPONSE (not the live cart): receipt number, date, totals, qr token', () => {
    const data = buildEscPosRefundReceiptData(makeResponse(), makeContext());

    expect(data.receipt_number).toBe('L01-T01-00043');
    expect(data.date_time).toBe('2026-06-10T09:00:00Z');
    expect(data.qr_token).toBe('v:kid:c0000000-0000-4000-8000-000000000001:mac');
    expect(data.company.name).toBe('Acme Shop');
    expect(data.company.country).toBe('FR');
    expect(data.terminal_name).toBe('T1');
    expect(data.operator_name).toBe('Alice');
  });

  it('passes the server-signed NEGATIVE amounts through verbatim (the template prints strings as-is)', () => {
    const data = buildEscPosRefundReceiptData(makeResponse(), makeContext());

    expect(data.subtotal).toBe('-16.81');
    expect(data.tax_amount).toBe('-3.19');
    expect(data.total).toBe('-20.00');
    expect(data.lines).toHaveLength(1);
    expect(data.lines[0]?.name).toBe('Widget A');
    expect(data.lines[0]?.quantity).toBe('-2.0000');
    expect(data.lines[0]?.unit_price).toBe('10.00');
    expect(data.lines[0]?.line_total).toBe('-20.00');
  });

  it('formats monetary amounts at the receipt currency scale (TND = 3 decimals)', () => {
    const data = buildEscPosRefundReceiptData(
      makeResponse({
        currency: 'TND',
        subtotal: '-16.81',
        tax_amount: '-3.19',
        total: '-20.0',
        lines: [
          {
            product_name: 'Widget A',
            quantity: '-2.0000',
            unit_price: '10.0000',
            line_total: '-20.0000',
          },
        ],
      }),
      makeContext(),
    );

    expect(data.subtotal).toBe('-16.810');
    expect(data.tax_amount).toBe('-3.190');
    expect(data.total).toBe('-20.000');
    expect(data.lines[0]?.unit_price).toBe('10.000');
    expect(data.lines[0]?.line_total).toBe('-20.000');
  });

  it('handles a null original receipt reference (resumed drafts without local context)', () => {
    const data = buildEscPosRefundReceiptData(
      makeResponse(),
      makeContext({ originalReceiptNumber: null, originalReceiptQrToken: null }),
    );

    expect(data.receipt_kind).toBe('refund');
    expect(data.original_receipt_number).toBeNull();
    expect(data.original_receipt_qr_token).toBeNull();
  });

  it('omits sections the /return response does not carry (payments, VAT breakdown)', () => {
    const data = buildEscPosRefundReceiptData(makeResponse(), makeContext());

    expect(data.payments).toEqual([]);
    expect(data.vat_breakdown).toEqual([]);
    expect(data.show_payment_details).toBe(false);
    expect(data.show_vat_breakdown).toBe(false);
  });
});

describe('buildRefundVoucherTicketData — Phase 3 voucher ticket mapping', () => {
  function makeVoucher(overrides: Partial<IssuedVoucher> = {}): IssuedVoucher {
    return {
      id: 'voucher-uuid-1',
      code: 'VCH-ABC-123',
      initial_balance: '20.000',
      currency: 'TND',
      expires_at: '2026-12-31T23:59:59Z',
      redemption_mode: 'bearer',
      partner_id: null,
      ...overrides,
    };
  }

  it('maps the issued voucher (code / amount / expiry) onto the voucher ticket payload', () => {
    const ticket = buildRefundVoucherTicketData(makeVoucher(), {
      companyName: 'Acme Shop',
      companyCountryCode: 'TN',
      terminalName: 'T1',
      operatorName: 'Alice',
      issuedAt: '2026-06-10T09:00:00Z',
    });

    expect(ticket.code).toBe('VCH-ABC-123');
    // TND = 3 decimals through the shared currency-scale formatter.
    expect(ticket.initial_balance).toBe('20.000');
    expect(ticket.expires_at).toBe('2026-12-31T23:59:59Z');
    expect(ticket.issued_at).toBe('2026-06-10T09:00:00Z');
    expect(ticket.company.name).toBe('Acme Shop');
    expect(ticket.company.country).toBe('TN');
    expect(ticket.terminal_name).toBe('T1');
    expect(ticket.operator_name).toBe('Alice');
  });

  it("normalizes the server's snake_case redemption modes onto the template enum", () => {
    const bearer = buildRefundVoucherTicketData(
      makeVoucher({ redemption_mode: 'bearer' }),
      {
        companyName: 'Acme Shop',
        companyCountryCode: 'TN',
        terminalName: 'T1',
        operatorName: 'Alice',
        issuedAt: '2026-06-10T09:00:00Z',
      },
    );
    expect(bearer.redemption_mode).toBe('Bearer');

    const bound = buildRefundVoucherTicketData(
      makeVoucher({ redemption_mode: 'customer_bound' }),
      {
        companyName: 'Acme Shop',
        companyCountryCode: 'TN',
        terminalName: 'T1',
        operatorName: 'Alice',
        issuedAt: '2026-06-10T09:00:00Z',
      },
    );
    expect(bound.redemption_mode).toBe('CustomerBound');
  });
});
