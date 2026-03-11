import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
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

export function useMenus(params?: Record<string, string>) {
  return useQuery({
    queryKey: menuKeys.list(params as Record<string, unknown>),
    queryFn: () => getMenus(params),
  })
}

export function useMenu(id: string) {
  return useQuery({
    queryKey: menuKeys.detail(id),
    queryFn: () => getMenu(id),
    enabled: !!id,
  })
}

export function useCreateMenu() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (data: CreateMenuData) => createMenu(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

export function useUpdateMenu() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMenuData }) => updateMenu(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

export function useDeleteMenu() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteMenu(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

// Category mutations
export function useCreateMenuCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ menuId, data }: { menuId: string; data: CreateMenuCategoryData }) => createMenuCategory(menuId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

export function useUpdateMenuCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateMenuCategoryData }) => updateMenuCategory(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

export function useDeleteMenuCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => deleteMenuCategory(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

// Item mutations
export function useSyncMenuCategoryItems() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ categoryId, data }: { categoryId: string; data: SyncMenuCategoryItemsData }) =>
      syncMenuCategoryItems(categoryId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

export function useAddMenuCategoryItem() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ categoryId, data }: { categoryId: string; data: AddMenuCategoryItemData }) =>
      addMenuCategoryItem(categoryId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}

export function useRemoveMenuCategoryItem() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ categoryId, itemId }: { categoryId: string; itemId: string }) =>
      removeMenuCategoryItem(categoryId, itemId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: menuKeys.all })
    },
  })
}
