import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { fetchUnits, fetchCategories, createUnit, updateUnit, deleteUnit } from '../api/uomApi'
import type { CreateUnitInput } from '../api/uomApi'

/**
 * Query keys factory
 */
export const uomKeys = {
  all: ['uom'] as const,
  categories: () => [...uomKeys.all, 'categories'] as const,
  units: () => [...uomKeys.all, 'units'] as const,
  unitsByCategory: (categoryId?: string) =>
    [...uomKeys.units(), { categoryId }] as const,
}

/**
 * Fetch all categories
 */
export function useCategories() {
  return useQuery({
    queryKey: uomKeys.categories(),
    queryFn: fetchCategories,
  })
}

/**
 * Fetch all units (optionally filtered by category)
 */
export function useUnits(categoryId?: string) {
  return useQuery({
    queryKey: uomKeys.unitsByCategory(categoryId),
    queryFn: () => fetchUnits(categoryId),
  })
}

/**
 * Create a custom unit
 */
export function useCreateUnit() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createUnit,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: uomKeys.units() })
      queryClient.invalidateQueries({ queryKey: uomKeys.categories() })
    },
  })
}

/**
 * Update a unit
 */
export function useUpdateUnit() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: Partial<CreateUnitInput> }) =>
      updateUnit(id, input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: uomKeys.units() })
      queryClient.invalidateQueries({ queryKey: uomKeys.categories() })
    },
  })
}

/**
 * Delete (deactivate) a unit
 */
export function useDeleteUnit() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteUnit,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: uomKeys.units() })
      queryClient.invalidateQueries({ queryKey: uomKeys.categories() })
    },
  })
}
