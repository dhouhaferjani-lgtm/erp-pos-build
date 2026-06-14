export interface ReceiptVisibility {
  show_vat_breakdown: boolean;
  show_fiscal_info: boolean;
  show_payment_details: boolean;
  show_customer: boolean;
}

export interface CompanyConfig {
  all_enabled_modules: string[];
  receipt_visibility?: ReceiptVisibility;
  vertical?: string;
  smart_prompts_enabled?: boolean;
  smart_prompts_variant?: 'inline' | 'toast' | 'both' | 'off';
  allow_cross_location_stock_view?: boolean;
}
