import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api';
import type { App.Shared.Application.DTOs.PaginationData } from '@shared/types';

export interface KeyComponentTranslation {
  id?: string;
  locale: string;
  name: string;
  description?: string | null;
}

export interface CreateKeyComponentInput {
  slug: string;
  is_allergen: boolean;
  translations: KeyComponentTranslation[];
}

export interface UpdateKeyComponentInput {
  slug?: string;
  is_allergen?: boolean;
  translations?: KeyComponentTranslation[];
}

export interface KeyComponentsListResponse {
  data: App.Modules.Product.Application.DTOs.KeyComponentData[];
  meta: {
    pagination: App.Shared.Application.DTOs.PaginationData;
  };
}

export async function fetchKeyComponents(params?: {
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
}): Promise<KeyComponentsListResponse> {
  const queryParams = new URLSearchParams();
  if (params?.page) queryParams.set('page', params.page.toString());
  if (params?.per_page) queryParams.set('per_page', params.per_page.toString());
  if (params?.sort) queryParams.set('sort', params.sort);
  if (params?.direction) queryParams.set('direction', params.direction);

  return apiGet<KeyComponentsListResponse>(
    `/parapharmacy/key-components?${queryParams.toString()}`
  );
}

export async function fetchKeyComponent(
  id: string
): Promise<App.Modules.Product.Application.DTOs.KeyComponentData> {
  return apiGet<App.Modules.Product.Application.DTOs.KeyComponentData>(
    `/parapharmacy/key-components/${id}`
  );
}

export async function createKeyComponent(
  data: CreateKeyComponentInput
): Promise<App.Modules.Product.Application.DTOs.KeyComponentData> {
  return apiPost<App.Modules.Product.Application.DTOs.KeyComponentData>(
    '/parapharmacy/key-components',
    data
  );
}

export async function updateKeyComponent(
  id: string,
  data: UpdateKeyComponentInput
): Promise<App.Modules.Product.Application.DTOs.KeyComponentData> {
  return apiPatch<App.Modules.Product.Application.DTOs.KeyComponentData>(
    `/parapharmacy/key-components/${id}`,
    data
  );
}

export async function deleteKeyComponent(id: string): Promise<void> {
  return apiDelete(`/parapharmacy/key-components/${id}`);
}
