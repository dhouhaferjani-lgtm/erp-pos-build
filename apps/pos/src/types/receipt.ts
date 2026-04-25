/** Localized labels for ESC/POS thermal receipt printing. All optional — English defaults used if absent. */
export interface ReceiptLabels {
  receipt?: string
  date?: string
  terminal?: string
  operator?: string
  customer?: string
  item?: string
  qty?: string
  amount?: string
  subtotal?: string
  discount?: string
  tax?: string
  total?: string
  payments?: string
  change_due?: string
  vat_rate?: string
  taxable?: string
  tax_col?: string
  thank_you?: string
  tax_id?: string
  tel?: string
  notes?: string
}

export interface CreateReceiptRequest {
  terminal_id: string;
  lines: Array<{
    product_id?: string;
    composite_item_id?: string;
    quantity: number;
    unit_price: string;
    modifiers?: Array<{
      modifier_id: string;
      modifier_group_id: string;
      price_adjustment: string;
    }>;
    discount_type?: 'percentage' | 'fixed' | null;
    discount_percent?: string;
    discount_amount?: string;
    discount_reason?: string;
  }>;
  customer_id?: string;
  notes?: string;
  transaction_discount_amount?: string;
  transaction_discount_reason?: string;
  consumption_mode?: string;
  table_id?: string;
}

export interface CreateReceiptResponse {
  id: string;
  receipt_number: string;
  total: string;
  subtotal: string;
  tax_amount: string;
  discount_amount: string;
  currency: string;
}

export interface ProcessReceiptPaymentsRequest {
  payments: Array<{
    payment_method_id: string;
    amount: number;
    repository_id: string;
    card_last_four?: string;
    transaction_reference?: string;
    authorization_code?: string;
  }>;
  customer_id?: string;
}

/** Full receipt detail returned by GET /pos/receipts/{id} */
export interface FullReceiptResponse {
  id: string;
  receipt_number: string;
  receipt_type: string;
  posted_at: string;
  cashier_name: string;
  subtotal: string;
  tax_amount: string;
  discount_amount: string;
  total: string;
  /** Cash-sale tolerance write-off (GL 658). Null when no tolerance was applied. */
  tolerance_writeoff: string | null;
  currency: string;
  fiscal_hash: string | null;
  customer_name: string | null;
  notes: string | null;
  company: {
    name: string;
    address_street: string | null;
    address_street_2: string | null;
    address_city: string | null;
    address_postal_code: string | null;
    country_code: string;
    tax_id: string | null;
    phone: string | null;
  };
  terminal: {
    id: string;
    name: string;
    code: string;
  };
  lines: Array<{
    id: string;
    line_number: number;
    product_code: string;
    product_name: string;
    quantity: string;
    unit_price: string;
    line_total: string;
    tax_rate: string;
    tax_amount: string;
    discount_amount: string;
    modifiers: Array<{ name: string; price: string }> | null;
  }>;
  vat_details: Array<{
    tax_rate: string;
    net_amount: string;
    vat_amount: string;
    gross_amount: string;
  }>;
  payments: Array<{
    id: string;
    payment_method_id: string;
    payment_type: string;
    amount: string;
    payment_method: {
      id: string;
      name: string;
      code: string;
    };
  }>;
}

export interface ProcessReceiptPaymentsResponse {
  receipt: {
    id: string;
    receipt_number: string;
    total: string;
  };
  receipt_payments: Array<{
    id: string;
    payment_method_id: string;
    amount: string;
  }>;
  treasury_payments: Array<{
    id: string;
    journal_entry_id: string;
  }>;
  change_due: string;
}
