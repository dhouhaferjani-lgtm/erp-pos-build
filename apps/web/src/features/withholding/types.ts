import type {
  App_Modules_Taxation_Domain_Enums_WithholdingDirection,
  App_Modules_Taxation_Domain_Enums_CertificateStatus,
  App_Modules_Taxation_Domain_Enums_TransactionType,
} from '@shared/types/generated';

export type WithholdingDirection = App_Modules_Taxation_Domain_Enums_WithholdingDirection;
export type CertificateStatus = App_Modules_Taxation_Domain_Enums_CertificateStatus;
export type TransactionType = App_Modules_Taxation_Domain_Enums_TransactionType;

export interface WithholdingCalculation {
  gross_amount: string;
  withholding_rate: string;
  withholding_amount: string;
  net_amount: string;
  currency: string;
  rate_percentage: number;
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
  suggested_rate: number | null;
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
  rate_percentage: number;

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
  document_id?: string;
  payment_id?: string;
  currency: string;
  gross_amount: string;
  transaction_type?: TransactionType;
  manual_rate_percentage?: number;
  override_reason?: string;
}

export interface WithholdingPreviewRequest {
  partner_id: string;
  amount: string;
  currency: string;
  transaction_type?: TransactionType;
}

export interface VoidCertificateRequest {
  reason: string;
}

export interface SubmitToTEJRequest {
  tej_reference: string;
}

export interface CertificateFilters {
  direction?: WithholdingDirection;
  status?: CertificateStatus;
  year?: number;
  partner_id?: string;
  date_from?: string;
  date_to?: string;
  cursor?: string;
}

export interface CreateWithholdingRuleRequest {
  country_code: string;
  code: string;
  name: string;
  description?: string;
  transaction_type?: TransactionType;
  partner_tax_status?: string;
  min_amount?: string;
  rate: number;
  effective_from: string;
  effective_to?: string;
  is_active?: boolean;
}

export interface UpdateWithholdingRuleRequest {
  code?: string;
  name?: string;
  description?: string;
  transaction_type?: TransactionType;
  partner_tax_status?: string;
  min_amount?: string;
  rate?: number;
  effective_from?: string;
  effective_to?: string;
  is_active?: boolean;
}
