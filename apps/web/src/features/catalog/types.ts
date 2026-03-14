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
  parent_id?: number | null | undefined
  name: string
  slug?: string | undefined
  description?: string | undefined
  sort_order?: number | undefined
  is_active?: boolean | undefined
}

export interface UpdateCategoryData {
  parent_id?: number | null | undefined
  name?: string | undefined
  slug?: string | undefined
  description?: string | undefined
  sort_order?: number | undefined
  is_active?: boolean | undefined
}

export interface GetCategoriesParams {
  per_page?: number | undefined
  cursor?: string | null | undefined
  parent_id?: number | 'root' | null | undefined
  search?: string | undefined
  is_active?: boolean | undefined
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
