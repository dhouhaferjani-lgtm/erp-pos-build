import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api';
import type { CertificationData } from '../types';

export interface CertificationTranslation {
  id?: string;
  locale: string;
  name: string;
  description?: string | null;
}

export interface CreateCertificationInput {
  type: string;
  slug: string;
  certifying_body?: string | null;
  logo_url?: string | null;
  verification_url?: string | null;
  is_active: boolean;
  display_order: number;
  translations: CertificationTranslation[];
}

export interface UpdateCertificationInput {
  type?: string;
  slug?: string;
  certifying_body?: string | null;
  logo_url?: string | null;
  verification_url?: string | null;
  is_active?: boolean;
  display_order?: number;
  translations?: CertificationTranslation[];
}

export interface CertificationsListResponse {
  data: CertificationData[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

export async function fetchCertifications(params?: {
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
}): Promise<CertificationsListResponse> {
  const queryParams = new URLSearchParams();
  if (params?.page) queryParams.set('page', params.page.toString());
  if (params?.per_page) queryParams.set('per_page', params.per_page.toString());
  if (params?.sort) queryParams.set('sort', params.sort);
  if (params?.direction) queryParams.set('direction', params.direction);

  return apiGet<CertificationsListResponse>(
    `/parapharmacy/certifications?${queryParams.toString()}`
  );
}

export async function fetchCertification(
  id: string
): Promise<CertificationData> {
  return apiGet<CertificationData>(
    `/parapharmacy/certifications/${id}`
  );
}

export async function createCertification(
  data: CreateCertificationInput
): Promise<CertificationData> {
  return apiPost<CertificationData>(
    '/parapharmacy/certifications',
    data
  );
}

export async function updateCertification(
  id: string,
  data: UpdateCertificationInput
): Promise<CertificationData> {
  return apiPatch<CertificationData>(
    `/parapharmacy/certifications/${id}`,
    data
  );
}

export async function deleteCertification(id: string): Promise<void> {
  return apiDelete(`/parapharmacy/certifications/${id}`);
}
