import { apiGet } from '@/lib/api';

/**
 * Wire shape of GET /api/v1/pos/payment-policy.
 *
 * Verified against the server DTO
 * (apps/api/app/Modules/POS/Application/DTOs/PosPaymentPolicyDTO.php) — every
 * property below is present on every response; the endpoint always returns a
 * complete DTO.
 *
 * Money fields are STRINGS — the denomination arrives already normalized to
 * the company currency scale and must reach the signed payload unmutated.
 */
export interface PaymentPolicyResponse {
  companyId: string;
  currencyCode: string;
  currencyScale: number;
  cashRoundingEnabled: boolean;
  /**
   * NON-nullable. When rounding does not apply the server sends a canonical
   * zero at the company currency scale (e.g. '0.000'), never null.
   */
  cashRoundingDenomination: string;
  tenderToleranceEnabled: boolean;
  /** Fraction, NOT a percentage — '0.0050' means 0.5%. */
  tenderTolerancePercentage: string;
  /** Absolute ceiling in the COMPANY currency. */
  tenderToleranceMaxAmount: string;
  /** Server clock, ISO 8601 UTC with a `T` separator and `Z` suffix. */
  refreshedAt: string;
}

export async function fetchPaymentPolicy(): Promise<PaymentPolicyResponse> {
  return apiGet<PaymentPolicyResponse>('/pos/payment-policy');
}
