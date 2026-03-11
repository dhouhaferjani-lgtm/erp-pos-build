import { api, apiGet, apiPost, apiPatch, apiDelete, apiPut } from '@/lib/api'
import type {
  MenuData,
  MenuCategoryData,
  CreateMenuData,
  UpdateMenuData,
  CreateMenuCategoryData,
  UpdateMenuCategoryData,
  SyncMenuCategoryItemsData,
  AddMenuCategoryItemData,
  PaginatedResponse,
} from '../types/menu'

// Menus
export async function getMenus(params?: Record<string, string>): Promise<PaginatedResponse<MenuData>> {
  const response = await api.get<PaginatedResponse<MenuData>>('/menus', { params })
  return response.data
}

export async function getMenu(id: string): Promise<MenuData> {
  return apiGet(`/menus/${id}`)
}

export async function createMenu(data: CreateMenuData): Promise<MenuData> {
  return apiPost('/menus', data)
}

export async function updateMenu(id: string, data: UpdateMenuData): Promise<MenuData> {
  return apiPatch(`/menus/${id}`, data)
}

export async function deleteMenu(id: string): Promise<void> {
  return apiDelete(`/menus/${id}`)
}

// Menu Categories
export async function createMenuCategory(menuId: string, data: CreateMenuCategoryData): Promise<MenuCategoryData> {
  return apiPost(`/menus/${menuId}/categories`, data)
}

export async function updateMenuCategory(id: string, data: UpdateMenuCategoryData): Promise<MenuCategoryData> {
  return apiPatch(`/menu-categories/${id}`, data)
}

export async function deleteMenuCategory(id: string): Promise<void> {
  return apiDelete(`/menu-categories/${id}`)
}

// Menu Category Items
export async function syncMenuCategoryItems(categoryId: string, data: SyncMenuCategoryItemsData): Promise<MenuCategoryData> {
  return apiPut(`/menu-categories/${categoryId}/items`, data)
}

export async function addMenuCategoryItem(categoryId: string, data: AddMenuCategoryItemData): Promise<MenuCategoryData> {
  return apiPost(`/menu-categories/${categoryId}/items`, data)
}

export async function removeMenuCategoryItem(categoryId: string, itemId: string): Promise<void> {
  return apiDelete(`/menu-categories/${categoryId}/items/${itemId}`)
}

// Active Menu (POS-facing)
export async function getActiveMenu(): Promise<MenuData> {
  return apiGet('/active-menu')
}
