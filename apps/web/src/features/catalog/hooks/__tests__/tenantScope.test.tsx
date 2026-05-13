import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  useCategories as useCatalogCategories,
  useCategory as useCatalogCategory,
  useCategoryTree as useCatalogCategoryTree,
  useUpdateCategory as useUpdateCatalogCategory,
} from '../../api/queries'
import {
  useCompositeItem,
  useCompositeItemAvailability,
  useCompositeItems,
} from '../useCompositeItems'
import {
  useAssignModifierGroup,
  useModifierGroup,
  useModifierGroups,
} from '../useModifierGroups'
import {
  useCreateRecipe,
  useCreateVariant,
  useRecipe,
  useRecipes,
  useVariants,
} from '../useRecipes'

const mockGetCategories = vi.hoisted(() => vi.fn())
const mockGetCategoryTree = vi.hoisted(() => vi.fn())
const mockGetCategory = vi.hoisted(() => vi.fn())
const mockUpdateCategory = vi.hoisted(() => vi.fn())
const mockGetCompositeItems = vi.hoisted(() => vi.fn())
const mockGetCompositeItem = vi.hoisted(() => vi.fn())
const mockCheckCompositeItemAvailability = vi.hoisted(() => vi.fn())
const mockGetModifierGroups = vi.hoisted(() => vi.fn())
const mockGetModifierGroup = vi.hoisted(() => vi.fn())
const mockAssignModifierGroup = vi.hoisted(() => vi.fn())
const mockGetRecipes = vi.hoisted(() => vi.fn())
const mockGetRecipe = vi.hoisted(() => vi.fn())
const mockGetVariants = vi.hoisted(() => vi.fn())
const mockCreateRecipe = vi.hoisted(() => vi.fn())
const mockCreateVariant = vi.hoisted(() => vi.fn())

vi.mock('../../api/categories', () => ({
  createCategory: vi.fn(),
  deleteCategory: vi.fn(),
  getCategories: mockGetCategories,
  getCategory: mockGetCategory,
  getCategoryTree: mockGetCategoryTree,
  updateCategory: mockUpdateCategory,
}))

vi.mock('../../api/compositeItemApi', () => ({
  checkCompositeItemAvailability: mockCheckCompositeItemAvailability,
  createCompositeItem: vi.fn(),
  deleteCompositeItem: vi.fn(),
  duplicateCompositeItem: vi.fn(),
  getCompositeItem: mockGetCompositeItem,
  getCompositeItems: mockGetCompositeItems,
  updateCompositeItem: vi.fn(),
}))

vi.mock('../../api/modifierGroupApi', () => ({
  assignModifierGroup: mockAssignModifierGroup,
  createModifier: vi.fn(),
  createModifierGroup: vi.fn(),
  deleteModifier: vi.fn(),
  deleteModifierGroup: vi.fn(),
  getModifierGroup: mockGetModifierGroup,
  getModifierGroups: mockGetModifierGroups,
  removeModifierGroup: vi.fn(),
  updateModifier: vi.fn(),
  updateModifierGroup: vi.fn(),
}))

vi.mock('../../api/recipeApi', () => ({
  activateRecipe: vi.fn(),
  calculateRecipeCost: vi.fn(),
  createRecipe: mockCreateRecipe,
  createRecipeLine: vi.fn(),
  createVariant: mockCreateVariant,
  deleteRecipeLine: vi.fn(),
  deleteVariant: vi.fn(),
  getRecipe: mockGetRecipe,
  getRecipes: mockGetRecipes,
  getVariants: mockGetVariants,
  updateRecipe: vi.fn(),
  updateRecipeLine: vi.fn(),
  updateVariant: vi.fn(),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'user@example.test', tenant_id: tenantId, roles: [], email_verified_at: null },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity }, mutations: { retry: false } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockGetCategories.mockResolvedValue({ data: [] })
  mockGetCategoryTree.mockResolvedValue([])
  mockGetCategory.mockImplementation(async (id: number) => ({ id }))
  mockUpdateCategory.mockResolvedValue({ id: 7 })
  mockGetCompositeItems.mockResolvedValue({ data: [] })
  mockGetCompositeItem.mockResolvedValue({ id: 'ci-1' })
  mockCheckCompositeItemAvailability.mockResolvedValue({ available_quantity: 1, limiting_component: null, components: [] })
  mockGetModifierGroups.mockResolvedValue({ data: [] })
  mockGetModifierGroup.mockResolvedValue({ id: 'mg-1' })
  mockAssignModifierGroup.mockResolvedValue([])
  mockGetRecipes.mockResolvedValue([])
  mockGetRecipe.mockResolvedValue({ id: 'r-1' })
  mockGetVariants.mockResolvedValue([])
  mockCreateRecipe.mockResolvedValue({ id: 'r-new' })
  mockCreateVariant.mockResolvedValue({ id: 'v-new' })
})

afterEach(() => {
  resetTenant()
})

describe('catalog tenant scope', () => {
  it('wraps catalog read keys and gates missing tenant/company (.105-.142)', async () => {
    const queryClient = createClient()
    const { result } = renderHook(() => ({
      categories: useCatalogCategories(),
      categoryTree: useCatalogCategoryTree(),
      category: useCatalogCategory(7),
      compositeItems: useCompositeItems(),
      compositeItem: useCompositeItem('ci-1'),
      availability: useCompositeItemAvailability('ci-1', 'loc-1'),
      modifierGroups: useModifierGroups(),
      modifierGroup: useModifierGroup('mg-1'),
      recipes: useRecipes('ci-1'),
      recipe: useRecipe('r-1'),
      variants: useVariants('ci-1'),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(result.current.categories.isSuccess).toBe(true)
      expect(result.current.categoryTree.isSuccess).toBe(true)
      expect(result.current.category.isSuccess).toBe(true)
      expect(result.current.compositeItems.isSuccess).toBe(true)
      expect(result.current.compositeItem.isSuccess).toBe(true)
      expect(result.current.availability.isSuccess).toBe(true)
      expect(result.current.modifierGroups.isSuccess).toBe(true)
      expect(result.current.modifierGroup.isSuccess).toBe(true)
      expect(result.current.recipes.isSuccess).toBe(true)
      expect(result.current.recipe.isSuccess).toBe(true)
      expect(result.current.variants.isSuccess).toBe(true)
    })

    expect(queryClient.getQueryData(['categories', 'list', undefined, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['categories', 'tree', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['categories', 'detail', 7, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['compositeItems', 'list', undefined, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['compositeItems', 'detail', 'ci-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['compositeItems', 'detail', 'ci-1', 'availability', 'loc-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['modifierGroups', 'list', undefined, 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['modifierGroups', 'detail', 'mg-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['recipes', 'list', 'ci-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['recipes', 'detail', 'r-1', 'tenant-A', 'company-1'])).toBeDefined()
    expect(queryClient.getQueryData(['variants', 'list', 'ci-1', 'tenant-A', 'company-1'])).toBeDefined()

    resetTenant()
    const getCompositeCalls = mockGetCompositeItems.mock.calls.length
    renderHook(() => useCompositeItems(), { wrapper: wrapper(createClient()) })
    expect(mockGetCompositeItems).toHaveBeenCalledTimes(getCompositeCalls)
  })

  it('refetches only active-tenant catalog recipe/modifier cascades (.129-.142)', async () => {
    let compositeCalls = 0
    let recipeCalls = 0
    let variantCalls = 0
    let modifierGroupCalls = 0
    mockGetCompositeItems.mockImplementation(async () => ({ data: [`composite-${++compositeCalls}`] }))
    mockGetRecipes.mockImplementation(async () => [`recipe-${++recipeCalls}`])
    mockGetVariants.mockImplementation(async () => [`variant-${++variantCalls}`])
    mockGetModifierGroups.mockImplementation(async () => ({ data: [`modifier-${++modifierGroupCalls}`] }))

    const queryClient = createClient()
    queryClient.setQueryData(['compositeItems', 'list', undefined, 'tenant-B', 'company-1'], { marker: 'tenant-B-composites' })
    queryClient.setQueryData(['recipes', 'list', 'ci-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-recipes' })
    queryClient.setQueryData(['variants', 'list', 'ci-1', 'tenant-B', 'company-1'], { marker: 'tenant-B-variants' })

    const { result } = renderHook(() => ({
      compositeItems: useCompositeItems(),
      modifierGroups: useModifierGroups(),
      recipes: useRecipes('ci-1'),
      variants: useVariants('ci-1'),
      assignModifierGroup: useAssignModifierGroup(),
      createRecipe: useCreateRecipe(),
      createVariant: useCreateVariant(),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(compositeCalls).toBe(1)
      expect(modifierGroupCalls).toBe(1)
      expect(recipeCalls).toBe(1)
      expect(variantCalls).toBe(1)
    })

    await act(async () => {
      await result.current.createRecipe.mutateAsync({ compositeItemId: 'ci-1', data: {} })
    })
    await waitFor(() => {
      expect(compositeCalls).toBe(2)
      expect(recipeCalls).toBe(2)
    })
    expect(variantCalls).toBe(1)
    expect(modifierGroupCalls).toBe(1)

    await act(async () => {
      await result.current.createVariant.mutateAsync({ compositeItemId: 'ci-1', data: { code: 'V-1', name: 'Variant' } })
    })
    await waitFor(() => {
      expect(compositeCalls).toBe(3)
      expect(variantCalls).toBe(2)
    })

    await act(async () => {
      await result.current.assignModifierGroup.mutateAsync({ compositeItemId: 'ci-1', modifierGroupId: 'mg-1' })
    })
    await waitFor(() => {
      expect(compositeCalls).toBe(4)
    })

    expect(queryClient.getQueryData(['compositeItems', 'list', undefined, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-composites' })
    expect(queryClient.getQueryData(['recipes', 'list', 'ci-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-recipes' })
    expect(queryClient.getQueryData(['variants', 'list', 'ci-1', 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-variants' })
  })

  it('refetches catalog category detail/list/tree without touching sibling tenants (.105-.112)', async () => {
    let listCalls = 0
    let treeCalls = 0
    const detailCalls = new Map<number, number>()
    mockGetCategories.mockImplementation(async () => ({ data: [`list-${++listCalls}`] }))
    mockGetCategoryTree.mockImplementation(async () => [`tree-${++treeCalls}`])
    mockGetCategory.mockImplementation(async (id: number) => {
      detailCalls.set(id, (detailCalls.get(id) ?? 0) + 1)
      return { id, name: `category-${id}` }
    })

    const queryClient = createClient()
    queryClient.setQueryData(['categories', 'list', undefined, 'tenant-B', 'company-1'], { marker: 'tenant-B-categories' })

    const { result } = renderHook(() => ({
      categories: useCatalogCategories(),
      tree: useCatalogCategoryTree(),
      detail7: useCatalogCategory(7),
      detail99: useCatalogCategory(99),
      update: useUpdateCatalogCategory(),
    }), { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(listCalls).toBe(1)
      expect(treeCalls).toBe(1)
      expect(detailCalls.get(7)).toBe(1)
      expect(detailCalls.get(99)).toBe(1)
      expect(result.current.detail99.isSuccess).toBe(true)
    })

    await act(async () => {
      await result.current.update.mutateAsync({ id: 7, data: { name: 'Updated' } })
    })

    await waitFor(() => {
      expect(listCalls).toBe(2)
      expect(treeCalls).toBe(2)
      expect(detailCalls.get(7)).toBe(2)
    })
    expect(detailCalls.get(99)).toBe(1)
    expect(queryClient.getQueryData(['categories', 'list', undefined, 'tenant-B', 'company-1'])).toEqual({ marker: 'tenant-B-categories' })
  })
})
