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
