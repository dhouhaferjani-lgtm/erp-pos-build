import { useQuery, type UseQueryResult } from '@tanstack/react-query'
import { getProducts, getProduct } from '../api/products'
import type { GetProductsParams, PaginatedProductsResponse, Product } from '../types'

/**
 * Query key factory for products
 */
export const productKeys = {
  all: ['products'] as const,
  lists: () => [...productKeys.all, 'list'] as const,
  list: (params?: GetProductsParams) => [...productKeys.lists(), params] as const,
  details: () => [...productKeys.all, 'detail'] as const,
  detail: (id: string) => [...productKeys.details(), id] as const,
}

/**
 * Hook to fetch paginated list of products
 */
export function useProducts(
  params?: GetProductsParams
): UseQueryResult<PaginatedProductsResponse> {
  return useQuery({
    queryKey: productKeys.list(params),
    queryFn: () => getProducts(params),
    staleTime: 60000, // Consider data fresh for 1 minute
  })
}

/**
 * Hook to fetch a single product by ID
 */
export function useProduct(id: string): UseQueryResult<Product> {
  return useQuery({
    queryKey: productKeys.detail(id),
    queryFn: () => getProduct(id),
    enabled: Boolean(id),
  })
}
