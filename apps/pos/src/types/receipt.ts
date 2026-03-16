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
