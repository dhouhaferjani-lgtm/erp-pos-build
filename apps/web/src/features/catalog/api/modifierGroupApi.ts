import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api'
import type {
  ModifierGroupData,
  ModifierData,
  CreateModifierGroupData,
  CreateModifierData,
  PaginatedResponse,
} from '../types/compositeItem'

// Modifier Groups
export async function getModifierGroups(params?: {
  search?: string
  is_active?: boolean
  per_page?: number
  page?: number
}): Promise<PaginatedResponse<ModifierGroupData>> {
  const queryParams: Record<string, string> = {}
  if (params?.search) queryParams['search'] = params.search
  if (params?.is_active !== undefined) queryParams['is_active'] = String(params.is_active)
  if (params?.per_page) queryParams['per_page'] = String(params.per_page)
  if (params?.page) queryParams['page'] = String(params.page)
  return apiGet('/modifier-groups', queryParams)
}

export async function getModifierGroup(id: string): Promise<ModifierGroupData> {
  return apiGet(`/modifier-groups/${id}`)
}

export async function createModifierGroup(data: CreateModifierGroupData): Promise<ModifierGroupData> {
  return apiPost('/modifier-groups', data)
}

export async function updateModifierGroup(id: string, data: Partial<CreateModifierGroupData>): Promise<ModifierGroupData> {
  return apiPatch(`/modifier-groups/${id}`, data)
}

export async function deleteModifierGroup(id: string): Promise<void> {
  return apiDelete(`/modifier-groups/${id}`)
}

// Modifiers
export async function createModifier(groupId: string, data: CreateModifierData): Promise<ModifierData> {
  return apiPost(`/modifier-groups/${groupId}/modifiers`, data)
}

export async function updateModifier(id: string, data: Partial<CreateModifierData>): Promise<ModifierData> {
  return apiPatch(`/modifiers/${id}`, data)
}

export async function deleteModifier(id: string): Promise<void> {
  return apiDelete(`/modifiers/${id}`)
}

// Assign/remove modifier groups to composite items
export async function assignModifierGroup(
  compositeItemId: string,
  modifierGroupId: string,
  displayOrder?: number
): Promise<ModifierGroupData[]> {
  const response = await apiPost(`/composite-items/${compositeItemId}/modifier-groups`, {
    modifier_group_id: modifierGroupId,
    display_order: displayOrder ?? 0,
  })
  return response as unknown as ModifierGroupData[]
}

export async function removeModifierGroup(compositeItemId: string, modifierGroupId: string): Promise<void> {
  return apiDelete(`/composite-items/${compositeItemId}/modifier-groups/${modifierGroupId}`)
}
