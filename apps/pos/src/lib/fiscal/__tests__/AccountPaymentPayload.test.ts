import { describe, expect, it } from 'vitest';
import {
  ACCOUNT_PAYMENT_PAYLOAD_KEYS,
  goldenAccountPaymentPayload,
  type AccountPaymentPayload,
} from '../payloads/AccountPaymentPayload';

describe('AccountPaymentPayload', () => {
  it('exposes the locked top-level key set', () => {
    expect([...ACCOUNT_PAYMENT_PAYLOAD_KEYS].sort()).toEqual([
      'account_payment_uuid',
      'business_date',
      'cashier_id',
      'cashier_name',
      'currency_code',
      'currency_scale',
      'customer',
      'event_time_device',
      'local_balance_snapshot',
      'notes',
      'payment',
      'receipt_type_code',
      'references',
      'regime_extensions',
      'seller',
      'shift_id',
      'staleness',
      'terminal_id',
      'training_flag',
      'treasury_allocation_policy',
    ]);
  });

  it('builds the synced-customer golden fixture with all discriminants', () => {
    const payload: AccountPaymentPayload = goldenAccountPaymentPayload();

    expect(payload.receipt_type_code).toBe('ACCOUNT_PAYMENT');
    expect(payload.customer.customer_sync_status).toBe('synced');
    expect(payload.payment.amount).toBe('100.000');
    expect(payload.treasury_allocation_policy).toBe('FIFO');
    expect(payload.staleness.balance_snapshot_stale).toBe(false);
  });
});
