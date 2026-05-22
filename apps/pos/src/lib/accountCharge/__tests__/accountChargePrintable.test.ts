import { describe, expect, it } from 'vitest';
import { goldenAccountChargePayload } from '@/lib/fiscal/payloads/AccountChargePayload';
import { buildAccountChargePrintable } from '../accountChargePrintable';

describe('buildAccountChargePrintable', () => {
  it('maps ACCOUNT_CHARGE payload fields into printable receipt data', () => {
    const printable = buildAccountChargePrintable({
      payload: goldenAccountChargePayload(),
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      fiscalHash: 'a'.repeat(64),
      terminalName: 'Register 1',
    });

    expect(printable).toMatchObject({
      title: 'ACCOUNT CHARGE RECEIPT',
      accountChargeUuid: '66666666-6666-4666-8666-666666666666',
      amountChargedToAccount: '119.000',
      chargeAmount: '119.000',
      customerName: 'Mariam Ben Ali',
      customerCategory: 'individual',
      accountIdentifier: 'CUST-0001',
      sellerName: 'Default Seller',
      sellerTaxNumber: '1234567AM000',
      sellerAddress: '1 rue Test, 1000 Tunis, TN',
      terminalName: 'Register 1',
      terminalId: '33333333-3333-4333-8333-333333333333',
      shiftId: '22222222-2222-4222-8222-222222222222',
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      fiscalHash: 'a'.repeat(64),
      balanceBefore: '300.000',
      balanceAfter: '419.000',
      creditLimit: '500.000',
      creditAvailableBefore: '200.000',
      creditAvailableAfter: '81.000',
      customerSnapshotStale: false,
      balanceSnapshotStale: false,
      stalenessReason: null,
      trainingFlag: false,
    });
    expect(printable.lines).toEqual([
      expect.objectContaining({
        name: 'Default item',
        lineTotal: '119.000',
      }),
    ]);
    expect(printable.vatBreakdown).toEqual([
      expect.objectContaining({
        rate: '19.00',
        netAmount: '100.000',
        vatAmount: '19.000',
        grossAmount: '119.000',
      }),
    ]);
  });
});
