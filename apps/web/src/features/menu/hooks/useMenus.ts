import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import {
  getMenus,
  getMenu,
  createMenu,
  updateMenu,
  deleteMenu,
  createMenuCategory,
  updateMenuCategory,
  deleteMenuCategory,
  syncMenuCategoryItems,
  addMenuCategoryItem,
  removeMenuCategoryItem,
} from '../api/menuApi'
import type {
  CreateMenuData,
  UpdateMenuData,
  CreateMenuCategoryData,
  UpdateMenuCategoryData,
  SyncMenuCategoryItemsData,
  AddMenuCategoryItemData,
} from '../types/menu'

export const menuKeys = {
  all: ['menus'] as const,
  lists: () => [...menuKeys.all, 'list'] as const,
  list: (params?: Record<string, unknown>) => [...menuKeys.lists(), params] as const,
  details: () => [...menuKeys.all, 'detail'] as const,
  detail: (id: string) => [...menuKeys.details(), id] as const,
}

/**
 * Tenant-scoped predicate matching ANY [menus, ...] queryKey for the
 * given tenant + company. tenantScopedKey() suffixes t/c on leaf keys,
 * so a fixed wrap [...menuKeys.all] = [menus, t, c] is NOT a prefix of
 * leaf list/detail keys [menus, list, params, t, c] or [menus, detail,
 * id, t, c]. Predicate-based invalidation sidesteps the positional
 * mismatch.
 */
export function menusInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === 'menus' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useMenus(params?: Record<string, string>) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...menuKeys.list(params as Record<string, unknown>)]),
    queryFn: () => getMenus(params),
    enabled: !!tenantId && !!companyId,
  })
}

export function useMenu(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...menuKeys.detail(id)]),
    queryFn: () => getMenu(id),
    enabled: !!id && !!tenantId && !!companyId,
  })
}

export function useCreateMenu() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateMenuData) => createMenu(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateMenu() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMenuData }) => updateMenu(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useDeleteMenu() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteMenu(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

// Category mutations
export function useCreateMenuCategory() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ menuId, data }: { menuId: string; data: CreateMenuCategoryData }) => createMenuCategory(menuId, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useUpdateMenuCategory() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMenuCategoryData }) => updateMenuCategory(id, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useDeleteMenuCategory() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteMenuCategory(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

// Item mutations
export function useSyncMenuCategoryItems() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ categoryId, data }: { categoryId: string; data: SyncMenuCategoryItemsData }) =>
      syncMenuCategoryItems(categoryId, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useAddMenuCategoryItem() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ categoryId, data }: { categoryId: string; data: AddMenuCategoryItemData }) =>
      addMenuCategoryItem(categoryId, data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useRemoveMenuCategoryItem() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ categoryId, itemId }: { categoryId: string; itemId: string }) =>
      removeMenuCategoryItem(categoryId, itemId),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: menusInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}
