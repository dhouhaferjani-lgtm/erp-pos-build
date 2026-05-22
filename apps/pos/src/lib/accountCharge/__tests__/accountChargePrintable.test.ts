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
      customerName: 'Mariam Ben Ali',
      terminalName: 'Register 1',
      fiscalEventId: '99999999-9999-4999-8999-999999999999',
      fiscalHash: 'a'.repeat(64),
      balanceBefore: '300.000',
      balanceAfter: '419.000',
    });
    expect(printable.lines).toEqual([
      expect.objectContaining({
        name: 'Default item',
        lineTotal: '119.000',
      }),
    ]);
  });
});
