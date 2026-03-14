import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api';
import type { IngredientData } from '../types';

export interface IngredientTranslation {
  id?: string;
  locale: string;
  name: string;
  description?: string | null;
}

export interface CreateIngredientInput {
  slug: string;
  cas_number?: string | null;
  is_allergen: boolean;
  allergen_code?: string | null;
  regulatory_status: 'approved' | 'restricted' | 'banned';
  notes?: string | null;
  translations: IngredientTranslation[];
}

export interface UpdateIngredientInput {
  slug?: string;
  cas_number?: string | null;
  is_allergen?: boolean;
  allergen_code?: string | null;
  regulatory_status?: 'approved' | 'restricted' | 'banned';
  notes?: string | null;
  translations?: IngredientTranslation[];
}

export interface IngredientsListResponse {
  data: IngredientData[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
  };
}

export async function fetchIngredients(params?: {
  page?: number;
  per_page?: number;
  sort?: string;
  direction?: 'asc' | 'desc';
}): Promise<IngredientsListResponse> {
  const queryParams = new URLSearchParams();
  if (params?.page) queryParams.set('page', params.page.toString());
  if (params?.per_page) queryParams.set('per_page', params.per_page.toString());
  if (params?.sort) queryParams.set('sort', params.sort);
  if (params?.direction) queryParams.set('direction', params.direction);

  return apiGet<IngredientsListResponse>(
    `/parapharmacy/ingredients?${queryParams.toString()}`
  );
}

export async function fetchIngredient(
  id: string
): Promise<IngredientData> {
  return apiGet<IngredientData>(
    `/parapharmacy/ingredients/${id}`
  );
}

export async function createIngredient(
  data: CreateIngredientInput
): Promise<IngredientData> {
  return apiPost<IngredientData>(
    '/parapharmacy/ingredients',
    data
  );
}

export async function updateIngredient(
  id: string,
  data: UpdateIngredientInput
): Promise<IngredientData> {
  return apiPatch<IngredientData>(
    `/parapharmacy/ingredients/${id}`,
    data
  );
}

export async function deleteIngredient(id: string): Promise<void> {
  return apiDelete(`/parapharmacy/ingredients/${id}`);
}
