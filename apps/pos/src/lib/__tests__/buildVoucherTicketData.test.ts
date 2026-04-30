import { describe, it, expect, vi } from 'vitest';

// buildVoucherTicketData uses i18next for the localized labels — stub it so
// the builder runs in isolation. Mirrors the pattern in buildReceiptData.test.
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

import { buildVoucherTicketData } from '../buildReceiptData';
import type { VoucherTicketInput } from '../buildReceiptData';

function makeInput(overrides: Partial<VoucherTicketInput> = {}): VoucherTicketInput {
  return {
    code: 'VCH-ABC-123',
    initialBalance: '25.00',
    currency: 'EUR',
    expiresAt: '2026-12-31T23:59:59Z',
    redemptionMode: 'Bearer',
    issuedAt: '2026-04-30T10:15:00Z',
    companyName: 'Acme Shop',
    companyAddressLine1: '1 rue de la paix',
    companyCity: 'Paris',
    companyPostalCode: '75001',
    companyCountry: 'FR',
    companyTaxId: 'FR12345',
    terminalName: 'T1',
    operatorName: 'Alice',
    ...overrides,
  };
}

describe('buildVoucherTicketData — Phase H Block 2 sub-task 2c', () => {
  it('emits all required fields for the Tauri print_voucher_ticket payload', () => {
    const result = buildVoucherTicketData(makeInput());

    expect(result.code).toBe('VCH-ABC-123');
    expect(result.initial_balance).toBe('25.00');
    expect(result.currency_symbol).toBe('€');
    expect(result.expires_at).toBe('2026-12-31T23:59:59Z');
    expect(result.redemption_mode).toBe('Bearer');
    expect(result.issued_at).toBe('2026-04-30T10:15:00Z');
    expect(result.terminal_name).toBe('T1');
    expect(result.operator_name).toBe('Alice');
    // Company is passed through with the Receipt CompanyInfo shape
    expect(result.company.name).toBe('Acme Shop');
    expect(result.company.address_line1).toBe('1 rue de la paix');
    expect(result.company.city).toBe('Paris');
    expect(result.company.postal_code).toBe('75001');
    expect(result.company.country).toBe('FR');
    expect(result.company.tax_id).toBe('FR12345');
    // Labels object is populated (English defaults via mocked i18next)
    expect(result.labels).toBeDefined();
    expect(result.labels?.header).toBe('pos:receiptLabel.voucherTicket.header');
    expect(result.labels?.code).toBe('pos:receiptLabel.voucherTicket.code');
    expect(result.labels?.terms).toBe('pos:receiptLabel.voucherTicket.terms');
  });

  it('reflects bearer vs customer-bound on the redemption_mode field verbatim', () => {
    const bearer = buildVoucherTicketData(makeInput({ redemptionMode: 'Bearer' }));
    expect(bearer.redemption_mode).toBe('Bearer');

    const customerBound = buildVoucherTicketData(
      makeInput({ redemptionMode: 'CustomerBound' }),
    );
    expect(customerBound.redemption_mode).toBe('CustomerBound');
  });

  it('passes expires_at = null straight through for no-expiry vouchers', () => {
    const result = buildVoucherTicketData(makeInput({ expiresAt: null }));
    expect(result.expires_at).toBeNull();
  });

  it('formats the initial_balance to the currency display scale (EUR=2, TND=3, JPY=0)', () => {
    // EUR = 2 decimals → 25.00 stays at 2 decimals
    const eur = buildVoucherTicketData(makeInput({ currency: 'EUR', initialBalance: '25.000' }));
    expect(eur.initial_balance).toBe('25.00');

    // TND = 3 decimals
    const tnd = buildVoucherTicketData(makeInput({ currency: 'TND', initialBalance: '25.000' }));
    expect(tnd.initial_balance).toBe('25.000');

    // JPY = 0 decimals
    const jpy = buildVoucherTicketData(
      makeInput({ currency: 'JPY', initialBalance: '1500.000' }),
    );
    expect(jpy.initial_balance).toBe('1500');
  });

  it('handles missing optional company fields by emitting empty strings / null', () => {
    const result = buildVoucherTicketData(
      makeInput({
        companyAddressLine1: null,
        companyAddressLine2: null,
        companyCity: null,
        companyPostalCode: null,
        companyCountry: null,
        companyTaxId: null,
        companyPhone: null,
      }),
    );

    expect(result.company.address_line1).toBe('');
    expect(result.company.address_line2).toBeNull();
    expect(result.company.city).toBe('');
    expect(result.company.postal_code).toBe('');
    expect(result.company.country).toBe('');
    expect(result.company.tax_id).toBe('');
    expect(result.company.phone).toBeNull();
  });
});
