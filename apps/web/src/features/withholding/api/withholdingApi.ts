import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api';
import type {
  WithholdingPreviewRequest,
  WithholdingPreviewResponse,
  WithholdingCertificate,
  WithholdingRule,
  CreateWithholdingCertificateRequest,
  CreateWithholdingRuleRequest,
  UpdateWithholdingRuleRequest,
  VoidCertificateRequest,
  SubmitToTEJRequest,
  CertificateFilters,
} from '../types';

/**
 * Preview withholding calculation for a payment
 */
export async function previewWithholding(
  request: WithholdingPreviewRequest
): Promise<WithholdingPreviewResponse> {
  return apiPost<WithholdingPreviewResponse>('/withholding/preview', request);
}

/**
 * List withholding certificates with filters
 */
export async function fetchWithholdingCertificates(
  filters?: CertificateFilters
): Promise<{
  data: WithholdingCertificate[];
  meta: { per_page: number; has_more: boolean };
  links: { next: string | null; prev: string | null };
}> {
  const params = new URLSearchParams();

  if (filters?.direction) params.append('direction', filters.direction);
  if (filters?.status) params.append('status', filters.status);
  if (filters?.year) params.append('year', filters.year.toString());
  if (filters?.partner_id) params.append('partner_id', filters.partner_id);
  if (filters?.date_from) params.append('date_from', filters.date_from);
  if (filters?.date_to) params.append('date_to', filters.date_to);
  if (filters?.cursor) params.append('cursor', filters.cursor);

  const url = `/withholding/certificates${params.toString() ? `?${params.toString()}` : ''}`;

  return apiGet(url);
}

/**
 * Get a single withholding certificate
 */
export async function fetchWithholdingCertificate(
  id: string
): Promise<WithholdingCertificate> {
  return apiGet<WithholdingCertificate>(`/withholding/certificates/${id}`);
}

/**
 * Create a new withholding certificate
 */
export async function createWithholdingCertificate(
  request: CreateWithholdingCertificateRequest
): Promise<WithholdingCertificate> {
  return apiPost<WithholdingCertificate>('/withholding/certificates', request);
}

/**
 * Issue a certificate (finalize with hash chain)
 */
export async function issueWithholdingCertificate(
  id: string
): Promise<WithholdingCertificate> {
  return apiPost<WithholdingCertificate>(
    `/withholding/certificates/${id}/issue`
  );
}

/**
 * Void a certificate
 */
export async function voidWithholdingCertificate(
  id: string,
  request: VoidCertificateRequest
): Promise<WithholdingCertificate> {
  return apiPost<WithholdingCertificate>(
    `/withholding/certificates/${id}/void`,
    request
  );
}

/**
 * Submit certificate to TEJ
 */
export async function submitCertificateToTEJ(
  id: string,
  request: SubmitToTEJRequest
): Promise<WithholdingCertificate> {
  return apiPost<WithholdingCertificate>(
    `/withholding/certificates/${id}/submit-tej`,
    request
  );
}

/**
 * Delete a draft certificate
 */
export async function deleteWithholdingCertificate(id: string): Promise<void> {
  return apiDelete(`/withholding/certificates/${id}`);
}

/**
 * Download certificate as PDF
 */
export function downloadCertificatePDF(id: string): string {
  return `/api/v1/withholding/certificates/${id}/download-pdf`;
}

/**
 * Download TEJ XML for single certificate
 */
export function downloadCertificateTEJXML(id: string): string {
  return `/api/v1/withholding/certificates/${id}/download-tej-xml`;
}

/**
 * Download batch TEJ XML
 */
export function downloadBatchTEJXML(
  year?: number,
  direction?: string
): string {
  const params = new URLSearchParams();
  if (year) params.append('year', year.toString());
  if (direction) params.append('direction', direction);

  return `/api/v1/withholding/certificates/export-tej-batch${params.toString() ? `?${params.toString()}` : ''}`;
}

/**
 * List withholding tax rules
 */
export async function fetchWithholdingRules(
  countryCode?: string
): Promise<WithholdingRule[]> {
  const url = countryCode
    ? `/withholding/rules?country_code=${countryCode}`
    : '/withholding/rules';

  return apiGet<WithholdingRule[]>(url);
}

/**
 * Get a single withholding rule
 */
export async function fetchWithholdingRule(id: string): Promise<WithholdingRule> {
  return apiGet<WithholdingRule>(`/withholding/rules/${id}`);
}

/**
 * Create a new withholding rule
 */
export async function createWithholdingRule(
  request: CreateWithholdingRuleRequest
): Promise<WithholdingRule> {
  return apiPost<WithholdingRule>('/withholding/rules', request);
}

/**
 * Update an existing withholding rule
 */
export async function updateWithholdingRule(
  id: string,
  request: UpdateWithholdingRuleRequest
): Promise<WithholdingRule> {
  return apiPatch<WithholdingRule>(`/withholding/rules/${id}`, request);
}

/**
 * Delete a withholding rule
 */
export async function deleteWithholdingRule(id: string): Promise<void> {
  return apiDelete(`/withholding/rules/${id}`);
}

/**
 * Deactivate a withholding rule
 */
export async function deactivateWithholdingRule(id: string): Promise<WithholdingRule> {
  return apiPost<WithholdingRule>(`/withholding/rules/${id}/deactivate`);
}
