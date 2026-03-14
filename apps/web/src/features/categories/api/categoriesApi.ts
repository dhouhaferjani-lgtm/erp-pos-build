import { apiGet, apiPost, apiPut, apiDelete } from '../../../lib/api'

/**
 * Breadcrumb item in category path
 */
export interface CategoryBreadcrumb {
  id: number
  name: string
  slug: string
}

/**
 * Category API response (snake_case from backend)
 */
export interface CategoryApiResponse {
  id: number
  company_id: string
  parent_id: number | null
  name: string
  slug: string
  description: string | null
  image_url: string | null
  path: string
  depth: number
  sort_order: number
  is_active: boolean
  products_count: number | null
  breadcrumb: CategoryBreadcrumb[] | null
  children: CategoryApiResponse[] | null
}

/**
 * Paginated categories response
 */
export interface CategoriesListResponse {
  data: CategoryApiResponse[]
  meta?: {
    current_page: number
    from: number
    last_page: number
    per_page: number
    to: number
    total: number
  }
}

/**
 * Input for creating a new category
 */
export interface CreateCategoryInput {
  name: string
  parentId?: number | null | undefined
  description?: string | undefined
  image?: File | undefined
  isActive?: boolean | undefined
}

/**
 * Input for updating a category
 */
export interface UpdateCategoryInput {
  name?: string | undefined
  parentId?: number | null | undefined
  description?: string | undefined
  image?: File | undefined
  isActive?: boolean | undefined
}

/**
 * Input for reordering categories
 */
export interface ReorderCategoryInput {
  id: number
  parentId?: number | null | undefined
  sortOrder: number
}

/**
 * API payload format (snake_case for backend)
 */
interface CreateCategoryPayload {
  name: string
  parent_id?: number | null | undefined
  description?: string | undefined
  is_active?: boolean | undefined
}

interface UpdateCategoryPayload {
  name?: string | undefined
  parent_id?: number | null | undefined
  description?: string | undefined
  is_active?: boolean | undefined
}

interface ReorderCategoryPayload {
  categories: Array<{
    id: number
    parent_id?: number | null | undefined
    sort_order: number
  }>
}

/**
 * Fetches paginated list of categories
 */
export async function fetchCategories(params?: {
  page?: number
  perPage?: number
  search?: string
  parentId?: number | null
  isActive?: boolean
}): Promise<CategoriesListResponse> {
  const searchParams = new URLSearchParams()

  if (params?.page) searchParams.set('page', params.page.toString())
  if (params?.perPage) searchParams.set('per_page', params.perPage.toString())
  if (params?.search) searchParams.set('search', params.search)
  if (params?.parentId !== undefined) searchParams.set('parent_id', params.parentId?.toString() || '')
  if (params?.isActive !== undefined) searchParams.set('is_active', params.isActive ? '1' : '0')

  const queryString = searchParams.toString()
  return apiGet<CategoriesListResponse>(`/categories${queryString ? `?${queryString}` : ''}`)
}

/**
 * Fetches complete category tree
 */
export async function fetchCategoryTree(): Promise<CategoryApiResponse[]> {
  return apiGet<CategoryApiResponse[]>('/categories/tree')
}

/**
 * Fetches a single category by ID
 */
export async function fetchCategory(id: number): Promise<CategoryApiResponse> {
  return apiGet<CategoryApiResponse>(`/categories/${id}`)
}

/**
 * Creates a new category
 */
export async function createCategory(input: CreateCategoryInput): Promise<CategoryApiResponse> {
  const payload: CreateCategoryPayload = {
    name: input.name,
    parent_id: input.parentId,
    description: input.description,
    is_active: input.isActive ?? true,
  }

  // TODO: Handle image upload when file upload is implemented
  // For now, we only send the JSON data
  return apiPost<CategoryApiResponse>('/categories', payload)
}

/**
 * Updates an existing category
 */
export async function updateCategory(id: number, input: UpdateCategoryInput): Promise<CategoryApiResponse> {
  const payload: UpdateCategoryPayload = {}

  if (input.name !== undefined) payload.name = input.name
  if (input.parentId !== undefined) payload.parent_id = input.parentId
  if (input.description !== undefined) payload.description = input.description
  if (input.isActive !== undefined) payload.is_active = input.isActive

  // TODO: Handle image upload when file upload is implemented
  return apiPut<CategoryApiResponse>(`/categories/${id}`, payload)
}

/**
 * Deletes a category
 */
export async function deleteCategory(id: number): Promise<void> {
  return apiDelete(`/categories/${id}`)
}

/**
 * Reorders categories (batch update)
 */
export async function reorderCategories(categories: ReorderCategoryInput[]): Promise<void> {
  const payload: ReorderCategoryPayload = {
    categories: categories.map(c => ({
      id: c.id,
      parent_id: c.parentId,
      sort_order: c.sortOrder,
    })),
  }

  return apiPost<void>('/categories/reorder', payload)
}
