import type { POSProduct } from '@/types/product';
import type { CartItem, SelectedModifier } from '@/types/cart';
import type { PaymentMethod, PaymentRepository } from '@/types/payment';
import type { ModifierGroup, Modifier } from '@/types/modifier';
import type { OfflineReceipt } from '@/lib/db/repositories/offlineReceiptRepository';

export function makeProduct(overrides: Partial<POSProduct> = {}): POSProduct {
  return {
    id: 'prod-1',
    name: 'Test Product',
    sku: 'SKU-001',
    sale_price: '10.00',
    stock_quantity: 50,
    ...overrides,
  };
}

export function makeCartItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 'cart-item-1',
    product: {
      id: 'prod-1',
      name: 'Test Product',
      sku: 'SKU-001',
      price: '10.00',
    },
    quantity: 1,
    unit_price: '10.00',
    line_total: '10.00',
    tax_rate: '0',
    tax_amount: '0.00',
    ...overrides,
  };
}

export function makeSelectedModifier(overrides: Partial<SelectedModifier> = {}): SelectedModifier {
  return {
    modifier_id: 'mod-1',
    modifier_group_id: 'grp-1',
    name: 'Extra Cheese',
    group_name: 'Toppings',
    price_adjustment: '2.00',
    ...overrides,
  };
}

export function makeModifier(overrides: Partial<Modifier> = {}): Modifier {
  return {
    id: 'mod-1',
    name: 'Extra Cheese',
    price_adjustment: '2.00',
    is_default: false,
    is_active: true,
    position: 1,
    ...overrides,
  };
}

export function makeModifierGroup(overrides: Partial<ModifierGroup> = {}): ModifierGroup {
  return {
    id: 'grp-1',
    name: 'Toppings',
    selection_type: 'multiple',
    min_selections: 0,
    max_selections: 3,
    is_required: false,
    position: 1,
    modifiers: [
      makeModifier({ id: 'mod-1', name: 'Extra Cheese', price_adjustment: '2.00', position: 1 }),
      makeModifier({ id: 'mod-2', name: 'Bacon', price_adjustment: '3.00', position: 2 }),
    ],
    ...overrides,
  };
}

export function makePaymentMethod(overrides: Partial<PaymentMethod> = {}): PaymentMethod {
  return {
    id: 'pm-1',
    code: 'CASH',
    name: 'Cash',
    is_physical: true,
    has_maturity: false,
    requires_third_party: false,
    is_push: false,
    has_deducted_fees: false,
    is_restricted: false,
    is_cash_tender: true,
    fee_type: null,
    fee_fixed: '0',
    fee_percent: '0',
    restriction_type: null,
    is_active: true,
    position: 1,
    ...overrides,
  };
}

export function makePaymentRepository(overrides: Partial<PaymentRepository> = {}): PaymentRepository {
  return {
    id: 'repo-1',
    code: 'CR-001',
    name: 'Cash Register 1',
    type: 'cash_register',
    bank_name: null,
    account_number: null,
    iban: null,
    bic: null,
    balance: '500.00',
    is_active: true,
    ...overrides,
  };
}

export function makeOfflineReceipt(overrides: Partial<OfflineReceipt> = {}): OfflineReceipt {
  return {
    id: 'receipt-1',
    idempotency_key: 'idem-1',
    receipt_number: 'MAIN-T001-2026-00000001',
    terminal_id: 'terminal-1',
    terminal_code: 'T001',
    operator_id: 'op-1',
    operator_name: 'John',
    lines: JSON.stringify([
      {
        product_id: 'prod-1',
        name: 'Widget',
        sku: 'W-001',
        quantity: 2,
        unit_price: '25.00',
        line_total: '50.00',
        tax_amount: '0',
      },
    ]),
    subtotal: '50.00',
    tax_amount: '0.00',
    discount_amount: '0.00',
    total: '50.00',
    currency: 'EUR',
    fiscal_hash: 'abc123',
    previous_hash: 'genesis',
    hash_sequence: 1,
    transaction_discount_amount: null,
    transaction_discount_reason: null,
    tendered_amount: '50.00',
    change_due: '0.00',
    payment_method_id: 'pm-1',
    payment_repository_id: 'repo-1',
    status: 'pending',
    retry_count: 0,
    payments_json: '[{"payment_method_id":"pm-1","repository_id":"repo-1","amount":"50.00"}]',
    consumption_mode: null,
    table_id: null,
    server_receipt_id: null,
    fiscal_schema_version: 2,
    is_training: 0,
    created_at: '2026-01-01T00:00:00.000Z',
    synced_at: null,
    sync_error: null,
    ...overrides,
  };
}
