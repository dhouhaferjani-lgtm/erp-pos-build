export const ACCOUNT_PAYMENT_PAYLOAD_KEYS = [
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
] as const;

export interface AccountPaymentAddress {
  city: string;
  country_code: string;
  postal_code: string;
  street: string;
}

export interface AccountPaymentSeller {
  address: AccountPaymentAddress;
  name: string;
  tax_jurisdiction_country_code: string;
  tax_number: string;
}

export interface AccountPaymentCustomer {
  address: AccountPaymentAddress | null;
  customer_category: string | null;
  customer_id: string;
  customer_sync_status: 'synced' | 'pending_create';
  email: string | null;
  name: string;
  phone: string | null;
  tax_number: string | null;
}

export interface AccountPaymentPayment {
  amount: string;
  foreign_currency_amount: string | null;
  foreign_currency_code: string | null;
  instrument_serial: string | null;
  instrument_type: string | null;
  method_code: string;
  repository_id: string | null;
}

export interface AccountPaymentBalanceSnapshot {
  balance_updated_at: string;
  credit_balance_before: string;
  net_balance_before: string;
  payment_amount: string;
  projected_credit_balance_after: string;
  projected_net_balance_after: string;
  projected_receivable_balance_after: string;
  receivable_balance_before: string;
}

export interface AccountPaymentStaleness {
  balance_snapshot_stale: boolean;
  customer_snapshot_stale: boolean;
  mirror_last_synced_at: string | null;
  staleness_reason: 'never_synced' | 'older_than_threshold' | 'server_conflict_pending' | null;
}

export interface AccountPaymentReferences {
  external_reference: string | null;
  related_sale_receipt_event_id: string | null;
  server_customer_alias_id: string | null;
}

export interface AccountPaymentPayload {
  account_payment_uuid: string;
  business_date: string;
  cashier_id: string;
  cashier_name: string;
  currency_code: string;
  currency_scale: 0 | 2 | 3;
  customer: AccountPaymentCustomer;
  event_time_device: string;
  local_balance_snapshot: AccountPaymentBalanceSnapshot;
  notes: string | null;
  payment: AccountPaymentPayment;
  receipt_type_code: 'ACCOUNT_PAYMENT';
  references: AccountPaymentReferences | null;
  regime_extensions: Record<string, unknown> | null;
  seller: AccountPaymentSeller;
  shift_id: string;
  staleness: AccountPaymentStaleness;
  terminal_id: string;
  training_flag: boolean;
  treasury_allocation_policy: 'FIFO';
}

export function goldenAccountPaymentPayload(): AccountPaymentPayload {
  return {
    account_payment_uuid: '44444444-4444-4444-8444-444444444444',
    business_date: '2026-05-21',
    cashier_id: '11111111-1111-4111-8111-111111111111',
    cashier_name: 'Default Cashier',
    currency_code: 'TND',
    currency_scale: 3,
    customer: {
      address: null,
      customer_category: 'retail',
      customer_id: '55555555-5555-4555-8555-555555555555',
      customer_sync_status: 'synced',
      email: null,
      name: 'Mariam Ben Ali',
      phone: '+21611111111',
      tax_number: null,
    },
    event_time_device: '2026-05-21T10:15:30.000Z',
    local_balance_snapshot: {
      balance_updated_at: '2026-05-21T10:10:00.000Z',
      credit_balance_before: '0.000',
      net_balance_before: '300.000',
      payment_amount: '100.000',
      projected_credit_balance_after: '0.000',
      projected_net_balance_after: '200.000',
      projected_receivable_balance_after: '200.000',
      receivable_balance_before: '300.000',
    },
    notes: null,
    payment: {
      amount: '100.000',
      foreign_currency_amount: null,
      foreign_currency_code: null,
      instrument_serial: null,
      instrument_type: null,
      method_code: 'CASH',
      repository_id: null,
    },
    receipt_type_code: 'ACCOUNT_PAYMENT',
    references: null,
    regime_extensions: null,
    seller: {
      address: {
        city: 'Tunis',
        country_code: 'TN',
        postal_code: '1000',
        street: '1 rue Test',
      },
      name: 'Default Seller',
      tax_jurisdiction_country_code: 'TN',
      tax_number: '1234567A/A/A/000',
    },
    shift_id: '22222222-2222-4222-8222-222222222222',
    staleness: {
      balance_snapshot_stale: false,
      customer_snapshot_stale: false,
      mirror_last_synced_at: '2026-05-21T10:10:00.000Z',
      staleness_reason: null,
    },
    terminal_id: '33333333-3333-4333-8333-333333333333',
    training_flag: false,
    treasury_allocation_policy: 'FIFO',
  };
}

export const goldenAccountPaymentCanonicalBytes =
  '{"account_payment_uuid":"44444444-4444-4444-8444-444444444444","business_date":"2026-05-21","cashier_id":"11111111-1111-4111-8111-111111111111","cashier_name":"Default Cashier","currency_code":"TND","currency_scale":3,"customer":{"address":null,"customer_category":"retail","customer_id":"55555555-5555-4555-8555-555555555555","customer_sync_status":"synced","email":null,"name":"Mariam Ben Ali","phone":"+21611111111","tax_number":null},"event_time_device":"2026-05-21T10:15:30.000Z","local_balance_snapshot":{"balance_updated_at":"2026-05-21T10:10:00.000Z","credit_balance_before":"0.000","net_balance_before":"300.000","payment_amount":"100.000","projected_credit_balance_after":"0.000","projected_net_balance_after":"200.000","projected_receivable_balance_after":"200.000","receivable_balance_before":"300.000"},"notes":null,"payment":{"amount":"100.000","foreign_currency_amount":null,"foreign_currency_code":null,"instrument_serial":null,"instrument_type":null,"method_code":"CASH","repository_id":null},"receipt_type_code":"ACCOUNT_PAYMENT","references":null,"regime_extensions":null,"seller":{"address":{"city":"Tunis","country_code":"TN","postal_code":"1000","street":"1 rue Test"},"name":"Default Seller","tax_jurisdiction_country_code":"TN","tax_number":"1234567A/A/A/000"},"shift_id":"22222222-2222-4222-8222-222222222222","staleness":{"balance_snapshot_stale":false,"customer_snapshot_stale":false,"mirror_last_synced_at":"2026-05-21T10:10:00.000Z","staleness_reason":null},"terminal_id":"33333333-3333-4333-8333-333333333333","training_flag":false,"treasury_allocation_policy":"FIFO"}';
