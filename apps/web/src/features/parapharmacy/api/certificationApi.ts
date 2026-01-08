import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api';
import type { App.Shared.Application.DTOs.PaginationData } from '@shared/types';

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
  data: App.Modules.Product.Application.DTOs.CertificationData[];
  meta: {
    pagination: App.Shared.Application.DTOs.PaginationData;
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
): Promise<App.Modules.Product.Application.DTOs.CertificationData> {
  return apiGet<App.Modules.Product.Application.DTOs.CertificationData>(
    `/parapharmacy/certifications/${id}`
  );
}

export async function createCertification(
  data: CreateCertificationInput
): Promise<App.Modules.Product.Application.DTOs.CertificationData> {
  return apiPost<App.Modules.Product.Application.DTOs.CertificationData>(
    '/parapharmacy/certifications',
    data
  );
}

export async function updateCertification(
  id: string,
  data: UpdateCertificationInput
): Promise<App.Modules.Product.Application.DTOs.CertificationData> {
  return apiPatch<App.Modules.Product.Application.DTOs.CertificationData>(
    `/parapharmacy/certifications/${id}`,
    data
  );
}

export async function deleteCertification(id: string): Promise<void> {
  return apiDelete(`/parapharmacy/certifications/${id}`);
}
