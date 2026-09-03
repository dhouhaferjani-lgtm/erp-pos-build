import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  applyUnitTextMapping,
  createUnit,
  deleteUnit,
  fetchCategories,
  fetchUnits,
  fetchUnmappedUnitTexts,
  updateUnit,
} from '../api/uomApi'
import type { ApplyUnitTextMappingInput, CreateUnitInput } from '../api/uomApi'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

/**
 * Query keys factory.
 *
 * Returns un-scoped structural prefixes; the tenant + company scope is
 * appended at the useQuery call site via tenantScopedKey([...]). The
 * audit-tanstack-keys gate only approves a queryKey expression that is
 * either an array literal carrying an approved scope identifier OR a
 * bare-Identifier call expression `tenantScopedKey(...)`. A property-
 * access factory call like `uomKeys.units(...)` is neither, so the
 * wrap MUST happen at the call site.
 */
export const uomKeys = {
  all: ['uom'] as const,
  categories: () => [...uomKeys.all, 'categories'] as const,
  units: () => [...uomKeys.all, 'units'] as const,
  unmappedUnitTexts: () => [...uomKeys.all, 'unmapped-unit-texts'] as const,
  unitsByCategory: (categoryId?: string) =>
    [...uomKeys.units(), { categoryId }] as const,
}

export function useUnmappedUnitTexts() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...uomKeys.unmappedUnitTexts()]),
    queryFn: fetchUnmappedUnitTexts,
    enabled: !!tenantId && !!companyId,
  })
}

export function useApplyUnitTextMapping() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ApplyUnitTextMappingInput) => applyUnitTextMapping(input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: uomUnmappedUnitTextsInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

/**
 * Predicate factories for tenant-scoped invalidation across the
 * `[uom, units, ...]`, `[uom, categories, ...]` and
 * `[uom, unmapped-unit-texts, ...]` namespaces.
 *
 * `invalidateQueries({ queryKey })` matches by positional PREFIX, but
 * `tenantScopedKey()` appends tenant/company as a SUFFIX — wrapping a
 * filter key in `tenantScopedKey(...)` is a proven no-op for any leaf
 * key shape with intervening segments (e.g. `{ categoryId }`) and is
 * flagged by the audit-tanstack-keys gate for every invalidation-style
 * factory. Predicate-based invalidation sidesteps the positional issue.
 */
export function uomUnmappedUnitTextsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'uom' &&
      k[1] === 'unmapped-unit-texts' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function uomUnitsInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'uom' &&
      k[1] === 'units' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function uomCategoriesInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 4 &&
      k[0] === 'uom' &&
      k[1] === 'categories' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

/**
 * Fetch all categories
 */
export function useCategories() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...uomKeys.categories()]),
    queryFn: fetchCategories,
    enabled: !!tenantId && !!companyId,
  })
}

/**
 * Fetch all units (optionally filtered by category)
 */
export function useUnits(categoryId?: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...uomKeys.unitsByCategory(categoryId)]),
    queryFn: () => fetchUnits(categoryId),
    enabled: !!tenantId && !!companyId,
  })
}

/**
 * Create a custom unit
 */
export function useCreateUnit() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: createUnit,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: uomUnitsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: uomCategoriesInvalidationPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

/**
 * Update a unit
 */
export function useUpdateUnit() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: Partial<CreateUnitInput> }) =>
      updateUnit(id, input),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: uomUnitsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: uomCategoriesInvalidationPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}

/**
 * Delete (deactivate) a unit
 */
export function useDeleteUnit() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: deleteUnit,
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: uomUnitsInvalidationPredicate(tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: uomCategoriesInvalidationPredicate(tenantId, companyId),
        }),
      ])
    },
  })
}
