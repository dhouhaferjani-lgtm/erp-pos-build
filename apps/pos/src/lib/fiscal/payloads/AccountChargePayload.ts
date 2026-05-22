export const ACCOUNT_CHARGE_PAYLOAD_KEYS = [
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
] as const;

export interface AccountChargeAddress {
  city: string;
  country_code: string;
  postal_code: string;
  street: string;
}

export interface AccountChargeSeller {
  address: AccountChargeAddress;
  name: string;
  tax_jurisdiction_country_code: string;
  tax_number: string;
}

export interface AccountChargeBuyer {
  address: AccountChargeAddress | null;
  codice_fiscale: string | null;
  contact_id: string | null;
  customer_id: string | null;
  name: string;
  tax_number: string | null;
}

export interface AccountChargeCustomer {
  address: AccountChargeAddress | null;
  account_identifier: string | null;
  customer_category: string | null;
  customer_id: string;
  customer_sync_status: 'synced' | 'pending_create';
  email: string | null;
  name: string;
  phone: string | null;
  tax_number: string | null;
}

export interface AccountChargeLineItem {
  gtin: string | null;
  line_discount_amount: string;
  line_discount_reason: string | null;
  line_subtotal: string;
  line_uuid: string;
  line_vat: string;
  name: string;
  non_collected_subtype: string | null;
  product_id: string;
  quantity: string;
  sku: string | null;
  tax_category_code: string | null;
  unit_price: string;
  vat_rate: string;
}

export interface AccountChargeVatBreakdown {
  gross_amount: string;
  net_amount: string;
  rate: string;
  tax_category_code: string;
  vat_amount: string;
}

export interface AccountChargeBalanceSnapshot {
  balance_updated_at: string;
  charge_amount: string;
  credit_balance_before: string;
  net_balance_before: string;
  projected_credit_balance_after: string;
  projected_net_balance_after: string;
  projected_receivable_balance_after: string;
  receivable_balance_before: string;
}

export interface AccountChargeCreditDecision {
  credit_available_after: string | null;
  credit_available_before: string | null;
  credit_limit: string | null;
  decision: 'approved';
  limit_exceeded: boolean;
  mirror_stale_at_authoring: boolean;
  policy_version: string;
  stale_policy_action: 'allow' | 'warn' | 'block';
  warnings: string[];
}

export interface AccountChargeTerms {
  due_date: string | null;
  payment_terms_days: number | null;
  terms_label: string | null;
}

export interface AccountChargeTotals {
  amount_charged_to_account: string;
  grand_total_before_charge: string;
  subtotal: string;
  total: string;
  vat_total: string;
}

export interface AccountChargeStaleness {
  balance_snapshot_stale: boolean;
  customer_snapshot_stale: boolean;
  mirror_last_synced_at: string | null;
  staleness_reason: 'never_synced' | 'older_than_threshold' | 'server_conflict_pending' | null;
}

export interface AccountChargeReferences {
  external_reference: string | null;
  related_sale_receipt_event_id: string | null;
  server_customer_alias_id: string | null;
}

export interface AccountChargePayload {
  account_charge_uuid: string;
  business_date: string;
  buyer: AccountChargeBuyer | null;
  cashier_id: string;
  cashier_name: string;
  charge_terms: AccountChargeTerms;
  credit_decision: AccountChargeCreditDecision;
  currency_code: string;
  currency_scale: 0 | 2 | 3;
  customer: AccountChargeCustomer;
  event_time_device: string;
  invoice_classification: 'b2c_charge_receipt' | 'b2b_facture_draft_requested';
  line_items: AccountChargeLineItem[];
  local_balance_snapshot: AccountChargeBalanceSnapshot;
  notes: string | null;
  print_profile: 'ACCOUNT_CHARGE_RECEIPT';
  receipt_type_code: 'ACCOUNT_CHARGE';
  references: AccountChargeReferences | null;
  regime_extensions: Record<string, unknown> | null;
  seller: AccountChargeSeller;
  shift_id: string;
  staleness: AccountChargeStaleness;
  terminal_id: string;
  totals: AccountChargeTotals;
  training_flag: boolean;
  transaction_discount_amount: string;
  transaction_discount_reason: string | null;
  vat_breakdown: AccountChargeVatBreakdown[];
}

export function goldenAccountChargePayload(): AccountChargePayload {
  return {
    account_charge_uuid: '66666666-6666-4666-8666-666666666666',
    business_date: '2026-05-21',
    buyer: null,
    cashier_id: '11111111-1111-4111-8111-111111111111',
    cashier_name: 'Default Cashier',
    charge_terms: {
      due_date: '2026-06-20',
      payment_terms_days: 30,
      terms_label: 'Net 30',
    },
    credit_decision: {
      credit_available_after: '81.000',
      credit_available_before: '200.000',
      credit_limit: '500.000',
      decision: 'approved',
      limit_exceeded: false,
      mirror_stale_at_authoring: false,
      policy_version: 'phase3-default-v1',
      stale_policy_action: 'allow',
      warnings: [],
    },
    currency_code: 'TND',
    currency_scale: 3,
    customer: {
      address: null,
      account_identifier: 'CUST-0001',
      customer_category: 'individual',
      customer_id: '55555555-5555-4555-8555-555555555555',
      customer_sync_status: 'synced',
      email: null,
      name: 'Mariam Ben Ali',
      phone: '+21611111111',
      tax_number: null,
    },
    event_time_device: '2026-05-21T10:15:30.000Z',
    invoice_classification: 'b2c_charge_receipt',
    line_items: [
      {
        gtin: null,
        line_discount_amount: '0.000',
        line_discount_reason: null,
        line_subtotal: '100.000',
        line_uuid: '77777777-7777-4777-8777-777777777777',
        line_vat: '19.000',
        name: 'Default item',
        non_collected_subtype: null,
        product_id: 'prod-default',
        quantity: '1.000',
        sku: 'SKU-DEFAULT',
        tax_category_code: '',
        unit_price: '100.000',
        vat_rate: '19.00',
      },
    ],
    local_balance_snapshot: {
      balance_updated_at: '2026-05-21T10:10:00.000Z',
      charge_amount: '119.000',
      credit_balance_before: '0.000',
      net_balance_before: '300.000',
      projected_credit_balance_after: '0.000',
      projected_net_balance_after: '419.000',
      projected_receivable_balance_after: '419.000',
      receivable_balance_before: '300.000',
    },
    notes: null,
    print_profile: 'ACCOUNT_CHARGE_RECEIPT',
    receipt_type_code: 'ACCOUNT_CHARGE',
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
      tax_number: '1234567AM000',
    },
    shift_id: '22222222-2222-4222-8222-222222222222',
    staleness: {
      balance_snapshot_stale: false,
      customer_snapshot_stale: false,
      mirror_last_synced_at: '2026-05-21T10:10:00.000Z',
      staleness_reason: null,
    },
    terminal_id: '33333333-3333-4333-8333-333333333333',
    totals: {
      amount_charged_to_account: '119.000',
      grand_total_before_charge: '119.000',
      subtotal: '100.000',
      total: '119.000',
      vat_total: '19.000',
    },
    training_flag: false,
    transaction_discount_amount: '0.000',
    transaction_discount_reason: null,
    vat_breakdown: [
      {
        gross_amount: '119.000',
        net_amount: '100.000',
        rate: '19.00',
        tax_category_code: '',
        vat_amount: '19.000',
      },
    ],
  };
}

export const goldenAccountChargeCanonicalBytes =
  '{"account_charge_uuid":"66666666-6666-4666-8666-666666666666","business_date":"2026-05-21","buyer":null,"cashier_id":"11111111-1111-4111-8111-111111111111","cashier_name":"Default Cashier","charge_terms":{"due_date":"2026-06-20","payment_terms_days":30,"terms_label":"Net 30"},"credit_decision":{"credit_available_after":"81.000","credit_available_before":"200.000","credit_limit":"500.000","decision":"approved","limit_exceeded":false,"mirror_stale_at_authoring":false,"policy_version":"phase3-default-v1","stale_policy_action":"allow","warnings":[]},"currency_code":"TND","currency_scale":3,"customer":{"account_identifier":"CUST-0001","address":null,"customer_category":"individual","customer_id":"55555555-5555-4555-8555-555555555555","customer_sync_status":"synced","email":null,"name":"Mariam Ben Ali","phone":"+21611111111","tax_number":null},"event_time_device":"2026-05-21T10:15:30.000Z","invoice_classification":"b2c_charge_receipt","line_items":[{"gtin":null,"line_discount_amount":"0.000","line_discount_reason":null,"line_subtotal":"100.000","line_uuid":"77777777-7777-4777-8777-777777777777","line_vat":"19.000","name":"Default item","non_collected_subtype":null,"product_id":"prod-default","quantity":"1.000","sku":"SKU-DEFAULT","tax_category_code":"","unit_price":"100.000","vat_rate":"19.00"}],"local_balance_snapshot":{"balance_updated_at":"2026-05-21T10:10:00.000Z","charge_amount":"119.000","credit_balance_before":"0.000","net_balance_before":"300.000","projected_credit_balance_after":"0.000","projected_net_balance_after":"419.000","projected_receivable_balance_after":"419.000","receivable_balance_before":"300.000"},"notes":null,"print_profile":"ACCOUNT_CHARGE_RECEIPT","receipt_type_code":"ACCOUNT_CHARGE","references":null,"regime_extensions":null,"seller":{"address":{"city":"Tunis","country_code":"TN","postal_code":"1000","street":"1 rue Test"},"name":"Default Seller","tax_jurisdiction_country_code":"TN","tax_number":"1234567AM000"},"shift_id":"22222222-2222-4222-8222-222222222222","staleness":{"balance_snapshot_stale":false,"customer_snapshot_stale":false,"mirror_last_synced_at":"2026-05-21T10:10:00.000Z","staleness_reason":null},"terminal_id":"33333333-3333-4333-8333-333333333333","totals":{"amount_charged_to_account":"119.000","grand_total_before_charge":"119.000","subtotal":"100.000","total":"119.000","vat_total":"19.000"},"training_flag":false,"transaction_discount_amount":"0.000","transaction_discount_reason":null,"vat_breakdown":[{"gross_amount":"119.000","net_amount":"100.000","rate":"19.00","tax_category_code":"","vat_amount":"19.000"}]}';
