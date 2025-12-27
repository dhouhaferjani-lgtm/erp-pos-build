/**
 * Category types
 */
export interface Category {
  id: number
  company_id: number
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
  breadcrumb: Array<{
    id: number
    name: string
    slug: string
  }> | null
  children: Category[] | null
}

export interface CategoryTreeNode extends Category {
  children: CategoryTreeNode[]
}

export interface CreateCategoryData {
  parent_id?: number | null
  name: string
  slug?: string
  description?: string
  sort_order?: number
  is_active?: boolean
}

export interface UpdateCategoryData {
  parent_id?: number | null
  name?: string
  slug?: string
  description?: string
  sort_order?: number
  is_active?: boolean
}

export interface GetCategoriesParams {
  per_page?: number
  cursor?: string | null
  parent_id?: number | 'root' | null
  search?: string
  is_active?: boolean
}

export interface PaginatedCategoriesResponse {
  data: Category[]
  meta: {
    per_page: number
    has_more: boolean
  }
  links: {
    next: string | null
    prev: string | null
  }
}

export interface CategoryTreeResponse {
  data: CategoryTreeNode[]
}
