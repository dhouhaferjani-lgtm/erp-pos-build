import { api } from '@/lib/api'
import type {
  CreateExpenseDTO,
  CreateExpenseCategoryDTO,
  Expense,
  ExpenseCategory,
  ExpenseCategoryFilters,
  ExpenseCategoryListResponse,
  ExpenseCategoryResponse,
  ExpenseFilters,
  ExpenseListResponse,
  ExpenseResponse,
} from '../types'

/**
 * Expense API client
 */
export const expenseApi = {
  /**
   * List expenses with optional filters
   */
  list: async (filters?: ExpenseFilters): Promise<ExpenseListResponse> => {
    const { data } = await api.get<ExpenseListResponse>('/expenses', {
      params: filters,
    })
    return data
  },

  /**
   * Get a single expense by ID
   */
  get: async (id: string): Promise<Expense> => {
    const { data } = await api.get<ExpenseResponse>(`/expenses/${id}`)
    return data.data
  },

  /**
   * Create a new expense
   */
  create: async (expenseData: CreateExpenseDTO): Promise<Expense> => {
    const { data } = await api.post<ExpenseResponse>('/expenses', expenseData)
    return data.data
  },

  /**
   * Update an existing expense
   */
  update: async (id: string, expenseData: Partial<CreateExpenseDTO>): Promise<Expense> => {
    const { data } = await api.patch<ExpenseResponse>(`/expenses/${id}`, expenseData)
    return data.data
  },

  /**
   * Delete an expense (draft only)
   */
  delete: async (id: string): Promise<void> => {
    await api.delete(`/expenses/${id}`)
  },

  /**
   * Post an expense (finalize and create GL entries)
   */
  post: async (id: string): Promise<Expense> => {
    const { data } = await api.post<ExpenseResponse>(`/expenses/${id}/post`)
    return data.data
  },
}

/**
 * Expense category API client
 */
export const expenseCategoryApi = {
  /**
   * List expense categories with optional filters
   */
  list: async (filters?: ExpenseCategoryFilters): Promise<ExpenseCategory[]> => {
    const { data } = await api.get<ExpenseCategoryListResponse>('/expense-categories', {
      params: filters,
    })
    return data.data
  },

  /**
   * Get a single expense category by ID
   */
  get: async (id: string): Promise<ExpenseCategory> => {
    const { data } = await api.get<ExpenseCategoryResponse>(`/expense-categories/${id}`)
    return data.data
  },

  /**
   * Create a new expense category
   */
  create: async (categoryData: CreateExpenseCategoryDTO): Promise<ExpenseCategory> => {
    const { data } = await api.post<ExpenseCategoryResponse>(
      '/expense-categories',
      categoryData
    )
    return data.data
  },

  /**
   * Update an existing expense category
   */
  update: async (
    id: string,
    categoryData: Partial<CreateExpenseCategoryDTO>
  ): Promise<ExpenseCategory> => {
    const { data } = await api.patch<ExpenseCategoryResponse>(
      `/expense-categories/${id}`,
      categoryData
    )
    return data.data
  },

  /**
   * Delete an expense category
   */
  delete: async (id: string): Promise<void> => {
    await api.delete(`/expense-categories/${id}`)
  },
}
