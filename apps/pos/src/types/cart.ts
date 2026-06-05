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
    /**
     * T2 — variant identity carried on the cart line so the sale records
     * exactly which variant was sold. Populated when the cashier picks a
     * variant via the variant picker; left undefined for non-variant
     * products. `variant_name` is the human label (product name + variant
     * suffix) used for cart-line + receipt display.
     */
    variant_id?: string;
    variant_name?: string;
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
