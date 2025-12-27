import { apiGet, apiPost, apiPut, apiDelete } from '@/lib/api'
import type {
  Category,
  CategoryTreeNode,
  CreateCategoryData,
  GetCategoriesParams,
  PaginatedCategoriesResponse,
  UpdateCategoryData,
} from '../types'

/**
 * Get paginated list of categories
 */
export async function getCategories(params?: GetCategoriesParams): Promise<PaginatedCategoriesResponse> {
  const queryParams: Record<string, string> = {}

  if (params?.per_page) {
    queryParams['per_page'] = String(params.per_page)
  }

  if (params?.cursor) {
    queryParams['cursor'] = params.cursor
  }

  if (params?.parent_id !== undefined) {
    queryParams['parent_id'] = params.parent_id === 'root' ? 'root' : String(params.parent_id)
  }

  if (params?.search) {
    queryParams['search'] = params.search
  }

  if (params?.is_active !== undefined) {
    queryParams['is_active'] = params.is_active ? '1' : '0'
  }

  return apiGet<PaginatedCategoriesResponse>('/categories', queryParams)
}

/**
 * Get category tree (hierarchical structure)
 */
export async function getCategoryTree(): Promise<CategoryTreeNode[]> {
  return apiGet<CategoryTreeNode[]>('/categories/tree')
}

/**
 * Get a single category by ID
 */
export async function getCategory(id: number): Promise<Category> {
  return apiGet<Category>(`/categories/${id}`)
}

/**
 * Create a new category
 */
export async function createCategory(data: CreateCategoryData): Promise<Category> {
  return apiPost<Category>('/categories', data)
}

/**
 * Update an existing category
 */
export async function updateCategory(id: number, data: UpdateCategoryData): Promise<Category> {
  return apiPut<Category>(`/categories/${id}`, data)
}

/**
 * Delete a category
 */
export async function deleteCategory(id: number): Promise<void> {
  return apiDelete(`/categories/${id}`)
}

/**
 * Reorder categories (update sort_order and parent_id)
 */
export async function reorderCategories(
  categories: Array<{ id: number; sort_order: number; parent_id: number | null }>
): Promise<void> {
  await api.post('/categories/reorder', { categories })
}
