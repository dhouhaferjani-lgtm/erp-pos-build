import type { PosOverrideEvidence } from '@/lib/operatorApproval/posOverrideAuthoring';

export interface SelectedModifier {
  modifier_id: string;
  modifier_group_id: string;
  name: string;
  group_name: string;
  price_adjustment: string;
}

export interface CartItem {
  id: string;
  product: {
    id: string;
    name: string;
    sku: string;
    price: string;
    sellableType?: 'product' | 'composite_item';
    selectedModifiers?: SelectedModifier[];
    comboComponents?: string[];
  };
  quantity: number;
  unit_price: string;
  line_total: string;
  tax_rate: string;
  tax_amount: string;
  discount_type?: 'percentage' | 'fixed' | null;
  discount_percent?: string;
  discount_amount?: string;
  discount_reason?: string;
  discount_approval_evidence?: PosOverrideEvidence;
  /**
   * 'return'  — negative line from a refund/exchange (Returning section).
   * 'sale'    — normal positive purchase line (default when omitted).
   */
  kind?: 'return' | 'sale';
}
