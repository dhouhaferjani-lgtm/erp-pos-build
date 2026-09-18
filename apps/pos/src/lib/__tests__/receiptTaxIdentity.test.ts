import { describe, it, expect, vi } from 'vitest';

/**
 * DEV-QA-092 — the matricule fiscale must print ONCE on the ticket.
 *
 * i18next is resolved against the REAL `fr` POS bundle (not a key echo) so the
 * builders run with the labels a Tunisian terminal actually prints.
 */
vi.mock('i18next', async () => {
  const frPos = (await import('@/locales/fr/pos.json')).default as Record<string, unknown>;
  const resolve = (key: string): string => {
    const path = key.startsWith('pos:') ? key.slice(4) : key;
    let node: unknown = frPos;
    for (const segment of path.split('.')) {
      if (typeof node !== 'object' || node === null) return key;
      node = (node as Record<string, unknown>)[segment];
    }
    return typeof node === 'string' ? node : key;
  };
  return {
    default: {
      t: (key: string, opts?: Record<string, unknown>) => {
        const value = resolve(key);
        if (opts && typeof opts.name === 'string') {
          return value.replace('{{name}}', opts.name);
        }
        return value;
      },
    },
  };
});

// decimal.ts -> currency.ts reaches the auth store at module load time.
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import { dedupeVatNumber, normalizeTaxIdentifier } from '../receiptTaxIdentity';
import {
  buildEscPosReceiptData,
  buildEscPosFromOfflineReceipt,
  buildEscPosAccountPaymentReceiptData,
  buildEscPosAccountChargeReceiptData,
  buildEscPosRefundReceiptData,
} from '../buildReceiptData';
import type { RefundReceiptPrintContext } from '../buildReceiptData';
import { buildZReceiptData } from '../printing';
import type { FullReceiptResponse } from '@/types/receipt';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';
import type { AccountChargePrintable } from '@/lib/accountCharge/accountChargePrintable';
import type { ReturnSettlementResponse } from '@/lib/refundFlow/refundSettlementService';
import { goldenAccountPaymentPayload } from '@/lib/fiscal/payloads/AccountPaymentPayload';

// ── Fixtures ────────────────────────────────────────────────────────────────

const TN_MATRICULE = '1234567/A/M/000';

function makeReceipt(overrides: Partial<FullReceiptResponse> = {}): FullReceiptResponse {
  return {
    id: 'rcpt-092',
    receipt_number: 'RC-092',
    receipt_type: 'sale',
    posted_at: '2026-09-17T10:00:00Z',
    cashier_name: 'Alice',
    subtotal: '10.000',
    tax_amount: '0.000',
    discount_amount: '0.000',
    total: '10.000',
    tolerance_writeoff: null,
    currency: 'TND',
    fiscal_hash: null,
    customer_name: null,
    notes: null,
    company: {
      name: 'Café Nour',
      address_street: '1 Avenue Habib Bourguiba',
      address_street_2: null,
      address_city: 'Tunis',
      address_postal_code: '1000',
      country_code: 'TN',
      tax_id: 'COMPANY-TN-TAX',
      phone: null,
    },
    terminal: { id: 'term-1', name: 'Terminal 1', code: 'T1' },
    lines: [
      {
        id: 'line-1',
        line_number: 1,
        product_id: null,
        composite_item_id: null,
        menu_category_id: null,
        product_code: 'PROD-1',
        product_name: 'Café crème',
        quantity: '1',
        unit_price: '10.000',
        line_total: '10.000',
        tax_rate: '7.00',
        tax_amount: '0.000',
        discount_amount: '0.000',
        modifiers: null,
      },
    ],
    vat_details: [],
    payments: [],
    ...overrides,
  };
}

function makeLocation(vatNumber: string | null) {
  return {
    tax_id: TN_MATRICULE,
    vat_number: vatNumber,
    legal_identifiers: null,
    address_street: '9 Rue de la Kasbah',
    address_city: 'Tunis',
    address_postal_code: '1006',
    address_country: 'tn',
  };
}

function makeOfflineCheckoutResult(): CheckoutResult {
  return {
    isOffline: true,
    receiptId: 'offline-rcpt-092',
    receiptNumber: 'R-T1-2026-00000001',
    total: '20.000',
    subtotal: '20.000',
    taxAmount: '0.000',
    discountAmount: '0.000',
    vatBreakdown: [],
    changeDue: '0.000',
    currency: 'TND',
  } as unknown as CheckoutResult;
}

function makeAccountChargePrintable(): AccountChargePrintable {
  return {
    title: 'ACCOUNT CHARGE RECEIPT',
    accountChargeUuid: '66666666-6666-4666-8666-666666666666',
    fiscalEventId: 'fe-1',
    fiscalHash: 'hash-1',
    terminalName: 'Till 1',
    terminalId: 't',
    shiftId: 's',
    cashierName: 'Sam',
    businessDate: '2026-09-17',
    eventTimeDevice: '2026-09-17T10:15:30.000Z',
    sellerName: 'Café Nour',
    sellerTaxNumber: TN_MATRICULE,
    sellerAddress: '1 rue, Tunis',
    customerName: 'Mariam',
    customerPhone: '+21611111111',
    customerCategory: 'individual',
    accountIdentifier: 'CUST-0001',
    amountChargedToAccount: '119.000',
    chargeAmount: '119.000',
    subtotal: '100.000',
    vatTotal: '19.000',
    balanceBefore: '300.000',
    balanceAfter: '419.000',
    creditLimit: '500.000',
    creditAvailableBefore: '200.000',
    creditAvailableAfter: '81.000',
    dueDate: '2026-10-17',
    termsLabel: 'Net 30',
    customerSnapshotStale: false,
    balanceSnapshotStale: false,
    stalenessReason: null,
    trainingFlag: false,
    lines: [
      {
        name: 'Widget',
        quantity: '1.000',
        unitPrice: '119.000',
        lineSubtotal: '100.000',
        lineVat: '19.000',
        lineTotal: '119.000',
      },
    ],
    vatBreakdown: [
      {
        rate: '19.00',
        taxCategoryCode: '',
        netAmount: '100.000',
        vatAmount: '19.000',
        grossAmount: '119.000',
      },
    ],
  };
}

function makeReturnResponse(): ReturnSettlementResponse {
  return {
    id: 'c0000000-0000-4000-8000-000000000001',
    receipt_number: 'L01-T01-00043',
    receipt_type: 'return',
    original_receipt_id: 'b0000000-0000-4000-8000-000000000001',
    return_reason: 'defective',
    subtotal: '-16.810',
    tax_amount: '-3.190',
    total: '-20.000',
    currency: 'TND',
    posted_at: '2026-09-17T09:00:00Z',
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
  };
}

function makeRefundContext(): RefundReceiptPrintContext {
  return {
    companyName: 'Café Nour',
    companyCountryCode: 'TN',
    terminalName: 'T1',
    operatorName: 'Alice',
    originalReceiptNumber: 'L01-T01-00042',
    originalReceiptQrToken: 'v:kid:original-uuid:mac',
  };
}

// ── DEV-QA-092 — the pure helper ────────────────────────────────────────────

describe('normalizeTaxIdentifier', () => {
  it('trims, upper-cases and collapses internal whitespace', () => {
    expect(normalizeTaxIdentifier('  1234567/a/m/000  ')).toBe('1234567/A/M/000');
    expect(normalizeTaxIdentifier('1234567  /A/M/  000')).toBe('1234567 /A/M/ 000');
  });

  it('maps nullish and blank values onto the empty string', () => {
    expect(normalizeTaxIdentifier(null)).toBe('');
    expect(normalizeTaxIdentifier(undefined)).toBe('');
    expect(normalizeTaxIdentifier('   ')).toBe('');
  });
});

describe('dedupeVatNumber (DEV-QA-092)', () => {
  it('drops the VAT number when it equals the tax id — the matricule prints once', () => {
    expect(dedupeVatNumber(TN_MATRICULE, TN_MATRICULE)).toBeNull();
  });

  it('keeps a genuinely different VAT number', () => {
    expect(dedupeVatNumber('COMPANY-TN-TAX', 'FR40303265045')).toBe('FR40303265045');
  });

  it('treats a case-only or whitespace-only difference as equal', () => {
    expect(dedupeVatNumber(TN_MATRICULE, ' 1234567/a/m/000 ')).toBeNull();
    expect(dedupeVatNumber('  fr40303265045', 'FR40303265045  ')).toBeNull();
  });

  it('returns null for a blank or missing VAT number whatever the tax id', () => {
    expect(dedupeVatNumber(TN_MATRICULE, null)).toBeNull();
    expect(dedupeVatNumber(TN_MATRICULE, undefined)).toBeNull();
    expect(dedupeVatNumber(TN_MATRICULE, '   ')).toBeNull();
  });

  it('keeps the VAT number when there is no tax id to collide with', () => {
    expect(dedupeVatNumber(null, 'FR40303265045')).toBe('FR40303265045');
    expect(dedupeVatNumber('', 'FR40303265045')).toBe('FR40303265045');
  });

  it('returns the VAT number verbatim (dedup is a display decision, not a rewrite)', () => {
    expect(dedupeVatNumber('COMPANY-TN-TAX', ' fr40303265045 ')).toBe(' fr40303265045 ');
  });
});

// ── DEV-QA-092 — every ReceiptData producer ─────────────────────────────────

describe('buildEscPosReceiptData — vat_number dedup (DEV-QA-092)', () => {
  it('omits vat_number when the location VAT id IS the matricule fiscale (TN)', () => {
    const result = buildEscPosReceiptData(makeReceipt(), {
      sellerLocation: makeLocation(TN_MATRICULE),
    });

    expect(result.company.tax_id).toBe(TN_MATRICULE);
    expect(result.company.vat_number).toBeNull();
  });

  it('omits vat_number when it differs from the tax id only by case/whitespace', () => {
    const result = buildEscPosReceiptData(makeReceipt(), {
      sellerLocation: makeLocation('  1234567/a/m/000  '),
    });

    expect(result.company.vat_number).toBeNull();
  });

  it('still prints a genuinely different establishment VAT number', () => {
    const result = buildEscPosReceiptData(makeReceipt(), {
      sellerLocation: makeLocation('FR40303265045'),
    });

    expect(result.company.tax_id).toBe(TN_MATRICULE);
    expect(result.company.vat_number).toBe('FR40303265045');
  });
});

// ── r2 device recette 2026-09-18 — legal identifier lines ───────────────────
//
// The photo of the printed ticket showed the matricule TWICE — `MF : …` from
// `company.tax_id` AND `MATRICULE FISCAL: …` from
// `location.legal_identifiers` — plus `ESTABLISHMENT CODE: 002` in English on
// a French ticket. DEV-QA-092 deduped `vat_number` only; the legal lines are
// a THIRD surface for the same number.
//
// Fixture shape is the PharmaBio one (`DemoPharmacySeeder.php:315-318`):
// `legal_identifiers = { matricule_fiscal: <tax_id>, establishment_code: '002' }`.

describe('buildEscPosReceiptData — legal identifier lines (r2)', () => {
  const pharmaBio = (identifiers: Record<string, unknown>) => ({
    ...makeLocation(TN_MATRICULE),
    legal_identifiers: identifiers,
  });

  it('prints the matricule exactly ONCE across all three header surfaces', () => {
    const result = buildEscPosReceiptData(makeReceipt(), {
      sellerLocation: pharmaBio({
        matricule_fiscal: TN_MATRICULE,
        establishment_code: '002',
      }),
    });

    expect(result.company.tax_id).toBe(TN_MATRICULE);
    expect(result.company.vat_number).toBeNull();

    const lines = result.company.legal_identifier_lines ?? [];
    expect(lines.filter((line) => line.includes(TN_MATRICULE))).toEqual([]);
    expect(lines).toEqual(['Code établissement : 002']);
  });

  it('drops a legal line repeating the matricule under case/whitespace noise', () => {
    const result = buildEscPosReceiptData(makeReceipt(), {
      sellerLocation: pharmaBio({ matricule_fiscal: '  1234567/a/m/000  ' }),
    });

    expect(result.company.legal_identifier_lines).toBeNull();
  });

  it('keeps a legal identifier that is a genuinely different number', () => {
    const result = buildEscPosReceiptData(makeReceipt(), {
      sellerLocation: pharmaBio({ siret: '55210055400014' }),
    });

    // French typography for the translated label (space before the colon).
    expect(result.company.legal_identifier_lines).toEqual(['SIRET : 55210055400014']);
  });

  it('labels the establishment code in French, never in English', () => {
    const result = buildEscPosReceiptData(makeReceipt(), {
      sellerLocation: pharmaBio({ establishment_code: '002' }),
    });

    const printed = (result.company.legal_identifier_lines ?? []).join('\n');
    expect(printed).toContain('Code établissement');
    expect(printed).not.toContain('ESTABLISHMENT CODE');
  });
});

describe('buildZReceiptData — vat_number dedup (DEV-QA-092, Z header)', () => {
  const base = {
    companyName: 'Café Nour',
    formattedZNumber: 'Z0042',
    dateTime: '2026-09-17T10:00:00Z',
    terminalName: 'T1',
    operatorName: 'Alice',
    currencySymbol: 'TND',
    wasReused: false,
  };

  it('omits vat_number when it equals the Z header tax id', () => {
    const data = buildZReceiptData({ ...base, taxId: TN_MATRICULE, vatNumber: TN_MATRICULE });

    expect(data.company.tax_id).toBe(TN_MATRICULE);
    expect(data.company.vat_number).toBeNull();
  });

  it('omits vat_number on a case/whitespace-only difference', () => {
    const data = buildZReceiptData({
      ...base,
      taxId: TN_MATRICULE,
      vatNumber: ' 1234567/a/m/000 ',
    });

    expect(data.company.vat_number).toBeNull();
  });

  it('keeps a genuinely different VAT number on the Z header', () => {
    const data = buildZReceiptData({
      ...base,
      taxId: 'BRANCH-FR-TAX',
      vatNumber: 'FR40303265045',
    });

    expect(data.company.vat_number).toBe('FR40303265045');
  });
});

describe('the other ReceiptData producers never emit a duplicate tax identity', () => {
  it('offline receipt builder', () => {
    const data = buildEscPosFromOfflineReceipt(
      makeOfflineCheckoutResult(),
      [],
      'Café Nour',
      'Terminal 1',
      'Alice',
      'Espèces',
      undefined,
      'fr',
    );

    expect(data.company.vat_number ?? null).toBeNull();
  });

  it('account payment receipt builder', () => {
    const data = buildEscPosAccountPaymentReceiptData({
      payload: goldenAccountPaymentPayload(),
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      fiscalHash: 'b'.repeat(64),
      terminalName: 'Till 1',
    });

    expect(data.company.vat_number ?? null).toBeNull();
  });

  it('account charge receipt builder', () => {
    const data = buildEscPosAccountChargeReceiptData({
      payload: makeAccountChargePrintable(),
      currencyCode: 'TND',
    });

    expect(data.company.tax_id).toBe(TN_MATRICULE);
    expect(data.company.vat_number ?? null).toBeNull();
  });

  it('refund (AVOIR) receipt builder', () => {
    const data = buildEscPosRefundReceiptData(makeReturnResponse(), makeRefundContext());

    expect(data.company.vat_number ?? null).toBeNull();
  });
});
