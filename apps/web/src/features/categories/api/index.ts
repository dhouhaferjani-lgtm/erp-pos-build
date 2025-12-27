/**
 * Categories API
 * Types and functions for category management
 */

export type {
  CategoryBreadcrumb,
  CategoryApiResponse,
  CategoriesListResponse,
  CreateCategoryInput,
  UpdateCategoryInput,
  ReorderCategoryInput,
} from './categoriesApi'

export {
  fetchCategories,
  fetchCategoryTree,
  fetchCategory,
  createCategory,
  updateCategory,
  deleteCategory,
  reorderCategories,
} from './categoriesApi'
