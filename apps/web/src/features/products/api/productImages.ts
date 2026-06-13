import { api } from '@/lib/api'
import type { ProductMediaItem } from '../types'

/**
 * Get all images for a product
 */
export async function getProductImages(productId: string): Promise<ProductMediaItem[]> {
  const response = await api.get<{ data: ProductMediaItem[] }>(`/products/${productId}/images`)
  return response.data.data
}

/**
 * Upload a new image for a product
 */
export async function uploadProductImage(
  productId: string,
  file: File,
  sortOrder?: number
): Promise<ProductMediaItem> {
  const formData = new FormData()
  formData.append('image', file)
  if (sortOrder !== undefined) {
    formData.append('sort_order', String(sortOrder))
  }

  const response = await api.post<{ data: ProductMediaItem }>(
    `/products/${productId}/images`,
    formData,
    {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    }
  )
  return response.data.data
}

/**
 * Set an image as the primary image
 */
export async function setProductImagePrimary(
  productId: string,
  imageId: string
): Promise<ProductMediaItem> {
  const response = await api.patch<{ data: ProductMediaItem }>(
    `/products/${productId}/images/${imageId}`,
    { is_primary: true }
  )
  return response.data.data
}

/**
 * Update image sort order
 */
export async function updateProductImageSortOrder(
  productId: string,
  imageId: string,
  sortOrder: number
): Promise<ProductMediaItem> {
  const response = await api.patch<{ data: ProductMediaItem }>(
    `/products/${productId}/images/${imageId}`,
    { sort_order: sortOrder }
  )
  return response.data.data
}

/**
 * Reorder all images for a product
 */
export async function reorderProductImages(
  productId: string,
  imageIds: string[]
): Promise<ProductMediaItem[]> {
  const response = await api.post<{ data: ProductMediaItem[] }>(
    `/products/${productId}/images/reorder`,
    { image_ids: imageIds }
  )
  return response.data.data
}

/**
 * Delete an image
 */
export async function deleteProductImage(productId: string, imageId: string): Promise<void> {
  await api.delete(`/products/${productId}/images/${imageId}`)
}

/**
 * Get download URL for an image
 */
export function getProductImageDownloadUrl(productId: string, imageId: string): string {
  return `/api/v1/products/${productId}/images/${imageId}/download`
}

/**
 * Get public image URL (for e-commerce products)
 */
export async function getPublicProductImages(productId: string): Promise<
  Array<{
    id: string
    url: string
    is_primary: boolean
    sort_order: number
    role: string
    alt: string | null
    caption: string | null
  }>
> {
  const response = await api.get<{
    data: Array<{
      id: string
      url: string
      is_primary: boolean
      sort_order: number
      role: string
      alt: string | null
      caption: string | null
    }>
  }>(`/public/products/${productId}/images`)
  return response.data.data
}
