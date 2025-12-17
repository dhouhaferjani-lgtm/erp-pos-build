import { api } from '@/lib/api'
import type { GetProductsParams, PaginatedProductsResponse, Product } from '../types'

/**
 * Get paginated list of products
 */
export async function getProducts(params?: GetProductsParams): Promise<PaginatedProductsResponse> {
  const queryParams: Record<string, string> = {}

  if (params?.search) {
    queryParams['search'] = params.search
  }

  if (params?.type) {
    queryParams['type'] = params.type
  }

  if (params?.active !== undefined) {
    queryParams['active'] = params.active ? '1' : '0'
  }

  if (params?.per_page) {
    queryParams['per_page'] = String(params.per_page)
  }

  const response = await api.get<PaginatedProductsResponse>('/products', { params: queryParams })
  return response.data
}

/**
 * Get a single product by ID
 */
export async function getProduct(id: string): Promise<Product> {
  const response = await api.get<{ data: Product }>(`/products/${id}`)
  return response.data.data
}
