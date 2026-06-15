import { api, apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'

/**
 * Type aliases over the backend-generated DTOs (source of truth lives in
 * `packages/shared/types/generated.d.ts`, produced by `php artisan
 * typescript:transform`). Never hand-edit those generated types; re-export them
 * here for ergonomic local consumption.
 */
export type ProductAttribute = App.Modules.Catalog.Application.DTOs.ProductAttributeData
export type ProductAttributeValue = App.Modules.Catalog.Application.DTOs.ProductAttributeValueData
export type ProductVariant = App.Modules.Catalog.Application.DTOs.ProductVariantData
export type AttributeDataType = App.Modules.Catalog.Domain.Enums.AttributeDataType

export interface CreateAttributePayload {
  code: string
  name: string
  data_type: AttributeDataType
  is_variant_axis: boolean
  display_order?: number
}

export interface AddAttributeValuePayload {
  code: string
  label: string
  hex_color?: string | null
  image_url?: string | null
  display_order?: number
}

/**
 * Partial update of a variant. cost_override is advisory only (spec §6.7) — the
 * backend persists it for display and never feeds it into the inventory WAC
 * pipeline.
 */
export interface UpdateVariantPayload {
  variant_code?: string
  sku?: string
  name_suffix?: string
  is_active?: boolean
  display_order?: number
  barcode?: string | null
  price_override?: string | null
  cost_override?: string | null
  image_url?: string | null
}

// --- Attributes ---

export async function getAttributes(): Promise<ProductAttribute[]> {
  return apiGet<ProductAttribute[]>('/product-attributes')
}

export async function createAttribute(
  payload: CreateAttributePayload,
): Promise<ProductAttribute> {
  return apiPost<ProductAttribute>('/product-attributes', payload)
}

export async function deleteAttribute(attributeId: string): Promise<void> {
  await apiDelete<unknown>(`/product-attributes/${attributeId}`)
}

export async function getAttributeValues(
  attributeId: string,
): Promise<ProductAttributeValue[]> {
  return apiGet<ProductAttributeValue[]>(`/product-attributes/${attributeId}/values`)
}

export async function addAttributeValue(
  attributeId: string,
  payload: AddAttributeValuePayload,
): Promise<ProductAttributeValue> {
  return apiPost<ProductAttributeValue>(
    `/product-attributes/${attributeId}/values`,
    payload,
  )
}

// --- Variants ---

export async function getVariantsForProduct(
  productId: string,
): Promise<ProductVariant[]> {
  return apiGet<ProductVariant[]>(`/products/${productId}/variants`)
}

/**
 * One axis of the generate-matrix request: an attribute plus the subset of its
 * value ids the user wants to include in the cartesian product. Sending only the
 * checked values (rather than every value of the axis) lets the user generate a
 * bounded subset of the full matrix.
 */
export interface GenerateMatrixAxis {
  attribute_id: string
  value_ids: string[]
}

/**
 * Result envelope for generate-matrix. Unlike the unwrapped `apiPost` helper we
 * preserve `meta` here: the backend reports how many variants were created,
 * skipped (already existed), or restored (previously soft-deleted) so the UI can
 * surface an accurate success toast.
 */
export interface GenerateMatrixResult {
  data: ProductVariant[]
  meta: {
    created_count: number
    skipped_count: number
    restored_count: number
  }
}

export async function generateVariantMatrix(
  productId: string,
  axes: GenerateMatrixAxis[],
): Promise<GenerateMatrixResult> {
  const response = await api.post(
    `/products/${productId}/variants/generate-matrix`,
    { axes },
  )
  return response.data as GenerateMatrixResult
}

export async function updateVariant(
  variantId: string,
  payload: UpdateVariantPayload,
): Promise<ProductVariant> {
  return apiPatch<ProductVariant>(`/product-variants/${variantId}`, payload)
}

export async function deleteVariant(variantId: string): Promise<void> {
  await apiDelete<unknown>(`/product-variants/${variantId}`)
}
