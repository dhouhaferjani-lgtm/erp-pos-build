import { describe, it, expect, vi } from 'vitest';
import { getOfflineReceiptForPrint } from '../getOfflineReceiptForPrint';
import * as repo from '@/lib/db/repositories/offlineReceiptRepository';
import { makeOfflineReceipt, makePaymentMethod } from '@/test/helpers';

vi.mock('@/lib/db', () => ({ getDatabase: vi.fn(async () => ({ execute: vi.fn() })) }));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: {
    getState: vi.fn().mockReturnValue({
      companyId: 'company-1',
      companies: [{ id: 'company-1', name: 'Coffee Co', legalName: 'Coffee SA', countryCode: 'FR', currency: 'EUR', locale: 'fr', timezone: 'Europe/Paris' }],
    }),
  },
}));

vi.mock('@/stores/paymentStore', () => ({
  usePaymentStore: {
    getState: vi.fn().mockReturnValue({ paymentMethods: [] }),
  },
}));

import { usePaymentStore } from '@/stores/paymentStore';

describe('getOfflineReceiptForPrint', () => {
  it('assembles FullReceiptResponse-shaped data from offline_receipts + authStore, resolving payment method names', async () => {
    (usePaymentStore.getState as ReturnType<typeof vi.fn>).mockReturnValue({
      paymentMethods: [makePaymentMethod({ id: 'pm-1', name: 'Cash', code: 'CASH' })],
    });

    vi.spyOn(repo, 'getReceiptByIdempotencyKey').mockResolvedValueOnce(
      makeOfflineReceipt({
        idempotency_key: 'idem-1',
        receipt_number: 'MAIN-T001-2026-00000001',
        total: '50.00',
        subtotal: '50.00',
        tax_amount: '0.00',
        operator_name: 'Alice',
        lines: JSON.stringify([{ name: 'Coffee', sku: 'C-001', quantity: 1, unit_price: '50.00', line_total: '50.00', tax_rate: '0', tax_amount: '0.00' }]),
        payments_json: JSON.stringify([{ payment_method_id: 'pm-1', repository_id: 'repo-1', amount: '50.00' }]),
      }),
    );

    const result = await getOfflineReceiptForPrint('idem-1');

    expect(result.receipt_number).toBe('MAIN-T001-2026-00000001');
    expect(result.total).toBe('50.00');
    expect(result.cashier_name).toBe('Alice');
    expect(result.lines).toHaveLength(1);
    expect(result.lines[0]!.product_name).toBe('Coffee');
    expect(result.payments).toHaveLength(1);
    expect(result.payments[0]!.payment_method.name).toBe('Cash');
    expect(result.payments[0]!.payment_type).toBe('CASH');
    expect(result.company.name).toBe('Coffee Co');
  });

  it('throws if receipt not found in SQLite', async () => {
    vi.spyOn(repo, 'getReceiptByIdempotencyKey').mockResolvedValueOnce(null);

    await expect(getOfflineReceiptForPrint('idem-missing')).rejects.toThrow(/not found/i);
  });

  it('resolves split payment names and falls back to UUID for unknown methods', async () => {
    (usePaymentStore.getState as ReturnType<typeof vi.fn>).mockReturnValue({
      paymentMethods: [makePaymentMethod({ id: 'pm-1', name: 'Cash', code: 'CASH' })],
    });

    vi.spyOn(repo, 'getReceiptByIdempotencyKey').mockResolvedValueOnce(
      makeOfflineReceipt({
        idempotency_key: 'idem-split',
        total: '100.00',
        subtotal: '100.00',
        payments_json: JSON.stringify([
          { payment_method_id: 'pm-1', repository_id: 'repo-1', amount: '60.00' },
          { payment_method_id: 'pm-unknown-uuid', repository_id: 'repo-1', amount: '40.00' },
        ]),
      }),
    );

    const result = await getOfflineReceiptForPrint('idem-split');

    expect(result.payments).toHaveLength(2);
    // First payment: resolved from paymentMethods
    expect(result.payments[0]!.payment_method.name).toBe('Cash');
    expect(result.payments[0]!.payment_type).toBe('CASH');
    // Second payment: no match — falls back to UUID
    expect(result.payments[1]!.payment_method.name).toBe('pm-unknown-uuid');
    expect(result.payments[1]!.payment_type).toBe('unknown');
    expect(result.payments[1]!.payment_method.code).toBe('unknown');
  });
});
