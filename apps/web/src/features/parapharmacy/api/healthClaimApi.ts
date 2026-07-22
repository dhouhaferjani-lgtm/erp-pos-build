import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api';
import type { HealthClaimData } from '../types';
import type { OffsetPaginationMeta } from '@/types/pagination';

export interface HealthClaimTranslation {
  id?: string;
  locale: string;
  claim: string;
  disclaimer_text?: string | null;
}

export interface CreateHealthClaimInput {
  claim_type: 'function' | 'reduction_of_disease_risk' | 'development_and_health';
  slug: string;
  regulatory_status: 'approved' | 'pending' | 'rejected';
  efsa_reference?: string | null;
  fda_reference?: string | null;
  country_restrictions?: string[] | null;
  requires_disclaimer: boolean;
  translations: HealthClaimTranslation[];
}

export interface UpdateHealthClaimInput {
  claim_type?: 'function' | 'reduction_of_disease_risk' | 'development_and_health';
  slug?: string;
  regulatory_status?: 'approved' | 'pending' | 'rejected';
  efsa_reference?: string | null;
  fda_reference?: string | null;
  country_restrictions?: string[] | null;
  requires_disclaimer?: boolean;
  translations?: HealthClaimTranslation[];
}

export interface HealthClaimsListResponse {
  data: HealthClaimData[];
  meta: OffsetPaginationMeta;
}

export async function fetchHealthClaims(params?: {
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
}): Promise<HealthClaimsListResponse> {
  const queryParams = new URLSearchParams();
  if (params?.page) queryParams.set('page', params.page.toString());
  if (params?.per_page) queryParams.set('per_page', params.per_page.toString());
  if (params?.sort) queryParams.set('sort', params.sort);
  if (params?.direction) queryParams.set('direction', params.direction);

  return apiGet<HealthClaimsListResponse>(
    `/parapharmacy/health-claims?${queryParams.toString()}`
  );
}

export async function fetchHealthClaim(
  id: string
): Promise<HealthClaimData> {
  return apiGet<HealthClaimData>(
    `/parapharmacy/health-claims/${id}`
  );
}

export async function createHealthClaim(
  data: CreateHealthClaimInput
): Promise<HealthClaimData> {
  return apiPost<HealthClaimData>(
    '/parapharmacy/health-claims',
    data
  );
}

export async function updateHealthClaim(
  id: string,
  data: UpdateHealthClaimInput
): Promise<HealthClaimData> {
  return apiPatch<HealthClaimData>(
    `/parapharmacy/health-claims/${id}`,
    data
  );
}

export async function deleteHealthClaim(id: string): Promise<void> {
  return apiDelete(`/parapharmacy/health-claims/${id}`);
}
