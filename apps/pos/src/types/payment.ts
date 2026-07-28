export interface PaymentMethod {
  id: string;
  code: string;
  name: string;
  is_physical: boolean;
  has_maturity: boolean;
  requires_third_party: boolean;
  is_push: boolean;
  has_deducted_fees: boolean;
  is_restricted: boolean;
  /**
   * Cash-ness — the ONE predicate every device layer uses (spec §4.1).
   * Server invariant: `is_cash_tender === true` implies `code === 'CASH'`
   * EXACT (case-sensitive), enforced by PaymentMethodController store/update.
   * NEVER re-derive cash-ness from is_physical/has_maturity: that legacy
   * predicate classifies MEAL_VOUCHER as cash.
   */
  is_cash_tender: boolean;
  fee_type: string | null;
  fee_fixed: string;
  fee_percent: string;
  restriction_type: string | null;
  is_active: boolean;
  position: number;
}

export interface PaymentRepository {
  id: string;
  code: string;
  name: string;
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual';
  bank_name: string | null;
  account_number: string | null;
  iban: string | null;
  bic: string | null;
  balance: string;
  is_active: boolean;
}
