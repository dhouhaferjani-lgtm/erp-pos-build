import { describe, expect, it } from 'vitest';
import {
  ACCOUNT_CHARGE_PAYLOAD_KEYS,
  goldenAccountChargePayload,
  type AccountChargePayload,
} from '../payloads/AccountChargePayload';

describe('AccountChargePayload', () => {
  it('exposes the locked top-level key set', () => {
    expect([...ACCOUNT_CHARGE_PAYLOAD_KEYS].sort()).toEqual([
      'account_charge_uuid',
      'business_date',
      'buyer',
      'cashier_id',
      'cashier_name',
      'charge_terms',
      'credit_decision',
      'currency_code',
      'currency_scale',
      'customer',
      'event_time_device',
      'invoice_classification',
      'line_items',
      'local_balance_snapshot',
      'notes',
      'print_profile',
      'receipt_type_code',
      'references',
      'regime_extensions',
      'seller',
      'shift_id',
      'staleness',
      'terminal_id',
      'totals',
      'training_flag',
      'transaction_discount_amount',
      'transaction_discount_reason',
      'vat_breakdown',
    ]);
  });

  it('builds the individual-customer golden fixture with all discriminants', () => {
    const payload: AccountChargePayload = goldenAccountChargePayload();

    expect(payload.receipt_type_code).toBe('ACCOUNT_CHARGE');
    expect(payload.customer.customer_category).toBe('individual');
    expect(payload.customer.customer_sync_status).toBe('synced');
    expect(payload.customer.account_identifier).toBe('CUST-0001');
    expect(payload.print_profile).toBe('ACCOUNT_CHARGE_RECEIPT');
    expect(payload.invoice_classification).toBe('b2c_charge_receipt');
    expect(payload.totals.amount_charged_to_account).toBe('119.000');
    expect(payload.totals.grand_total_before_charge).toBe('119.000');
    expect(payload.local_balance_snapshot.projected_receivable_balance_after).toBe('419.000');
    expect(payload.credit_decision.policy_version).toBe('phase3-default-v1');
    expect(payload.credit_decision.credit_available_after).toBe('81.000');
    expect(payload.line_items[0]?.product_id).toBe('prod-default');
    expect(payload.line_items[0]?.line_uuid).toBe('77777777-7777-4777-8777-777777777777');
    expect(payload.line_items[0]?.non_collected_subtype).toBeNull();
    expect(payload.buyer).toBeNull();
  });
});
