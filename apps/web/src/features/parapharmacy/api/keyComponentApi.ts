import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api';
import type { KeyComponentData } from '../types';

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
  data: KeyComponentData[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
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
): Promise<KeyComponentData> {
  return apiGet<KeyComponentData>(
    `/parapharmacy/key-components/${id}`
  );
}

export async function createKeyComponent(
  data: CreateKeyComponentInput
): Promise<KeyComponentData> {
  return apiPost<KeyComponentData>(
    '/parapharmacy/key-components',
    data
  );
}

export async function updateKeyComponent(
  id: string,
  data: UpdateKeyComponentInput
): Promise<KeyComponentData> {
  return apiPatch<KeyComponentData>(
    `/parapharmacy/key-components/${id}`,
    data
  );
}

export async function deleteKeyComponent(id: string): Promise<void> {
  return apiDelete(`/parapharmacy/key-components/${id}`);
}
