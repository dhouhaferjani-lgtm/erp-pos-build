export type WithholdingDirection = 'sales' | 'purchase';
export type CertificateStatus = 'draft' | 'issued' | 'submitted' | 'voided';
export type TransactionType =
  | 'services'
  | 'goods'
  | 'rent'
  | 'honoraria'
  | 'dividends'
  | 'interest'
  | 'royalties'
  | 'other';

export interface WithholdingCalculation {
  gross_amount: string;
  withholding_rate: string;
  withholding_amount: string;
  net_amount: string;
  currency: string;
  rate_percentage: string;
  rule_id: string | null;
  rule_code: string | null;
  rule_name: string | null;
  transaction_type: TransactionType | null;
  override_reason: string | null;
  is_manual_override: boolean;
}

export interface WithholdingPreviewResponse {
  should_withhold: boolean;
  calculation: WithholdingCalculation | null;
  suggested_rate: string | null;
  // Flat properties (returned directly by some API variants)
  withholding_amount?: string;
  withholding_rate?: string;
  net_amount?: string;
  rate_percentage?: string;
  rule?: {
    id: string;
    code: string;
    name: string;
  } | null;
}

export interface WithholdingCertificate {
  id: string;
  certificate_number: string;
  year: number;
  reference: string;
  direction: WithholdingDirection;
  direction_label: string;
  status: CertificateStatus;
  status_label: string;

  // Partner info
  partner_id: string;
  partner?: {
    id: string;
    name: string;
    vat_number: string | null;
  };

  // Source documents
  document_id: string | null;
  payment_id: string | null;

  // Amounts
  currency: string;
  gross_amount: string;
  withholding_rate: string;
  withholding_amount: string;
  net_amount: string;
  rate_percentage: number;

  // Rule info
  withholding_rule_id: string | null;
  rule?: {
    id: string;
    code: string;
    name: string;
  };
  override_reason: string | null;
  is_manual_override: boolean;

  // TEJ submission
  tej_reference: string | null;
  tej_submitted_at: string | null;
  is_submitted_to_tej: boolean;

  // Certificate file
  certificate_media_id: string | null;

  // Fiscal chain
  hash: string | null;
  previous_hash: string | null;
  chain_sequence: number | null;

  // Metadata
  issued_at: string | null;
  issued_by: string | null;
  issuer?: {
    id: string;
    name: string;
  };
  created_at: string;
  updated_at: string;

  // Permissions/actions
  can_be_modified: boolean;
  can_be_issued: boolean;
  can_be_submitted: boolean;
  can_be_voided: boolean;

  // GL account
  gl_account_code: string;
}

export interface WithholdingRule {
  id: string;
  country_code: string;
  company_id: string | null;
  is_global: boolean;
  code: string;
  name: string;
  description: string | null;
  display_name: string;

  // Conditions
  transaction_type: TransactionType | null;
  transaction_type_label: string | null;
  partner_tax_status: string | null;
  partner_tax_status_label: string | null;
  min_amount: string | null;

  // Rate
  rate: string;
  // Emitted as a numeric-string by WithholdingRuleResource (precision contract:
  // getRateAsPercentage() returns a bcmath string, no (float) launder).
  rate_percentage: string;

  // Validity
  effective_from: string;
  effective_to: string | null;
  is_active: boolean;
  is_effective_now: boolean;

  // Metadata
  created_at: string;
  updated_at: string;
}

export interface CreateWithholdingCertificateRequest {
  direction: WithholdingDirection;
  partner_id: string;
  document_id?: string | undefined;
  payment_id?: string | undefined;
  currency: string;
  gross_amount: string;
  transaction_type?: TransactionType | undefined;
  manual_rate_percentage?: number | undefined;
  override_reason?: string | undefined;
}

export interface WithholdingPreviewRequest {
  partner_id: string;
  amount: string;
  currency: string;
  transaction_type?: TransactionType | undefined;
}

export interface VoidCertificateRequest {
  reason: string;
}

export interface SubmitToTEJRequest {
  tej_reference: string;
}

export interface CertificateFilters {
  direction?: WithholdingDirection | undefined;
  status?: CertificateStatus | undefined;
  year?: number | undefined;
  partner_id?: string | undefined;
  date_from?: string | undefined;
  date_to?: string | undefined;
  cursor?: string | undefined;
}

export interface CreateWithholdingRuleRequest {
  country_code: string;
  code: string;
  name: string;
  description?: string | undefined;
  transaction_type?: TransactionType | undefined;
  partner_tax_status?: string | undefined;
  min_amount?: string | undefined;
  rate: number;
  effective_from: string;
  effective_to?: string | undefined;
  is_active?: boolean | undefined;
}

export interface UpdateWithholdingRuleRequest {
  code?: string | undefined;
  name?: string | undefined;
  description?: string | undefined;
  transaction_type?: TransactionType | undefined;
  partner_tax_status?: string | undefined;
  min_amount?: string | undefined;
  rate?: number | undefined;
  effective_from?: string | undefined;
  effective_to?: string | undefined;
  is_active?: boolean | undefined;
}

export interface SalesWithholdingTrackingRecord {
  id: string;
  tenantId: string;
  companyId: string;
  documentId: string;
  paymentId: string | null;
  customerId: string;
  customerName: string;
  invoiceAmount: string;
  withholdingRate: string;
  withholdingAmount: string;
  expectedReceivable: string;
  certificateNumber: string | null;
  certificateReceived: boolean;
  certificateReceivedAt: string | null;
  notes: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface SalesWithholdingTrackingFilters {
  filter?: 'pending';
}

export interface MarkCertificateReceivedRequest {
  certificate_number: string;
}
