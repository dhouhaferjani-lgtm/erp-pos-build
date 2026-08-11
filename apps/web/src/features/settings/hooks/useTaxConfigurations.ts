import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { taxConfigurationApi } from '../api/taxConfigurationApi'
import type { TaxConfigurationFormData } from '../types/tax'

export const taxConfigurationKeys = {
  all: ['tax-configurations'] as const,
  list: () => [...taxConfigurationKeys.all, 'list'] as const,
  detail: (id: string) => [...taxConfigurationKeys.all, 'detail', id] as const,
  documentTypes: () => [...taxConfigurationKeys.all, 'document-types'] as const,
  capabilities: () => [...taxConfigurationKeys.all, 'capabilities'] as const,
}

function useTaxConfigurationTenantScope(): boolean {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  return tenantId !== null && companyId !== null
}

export function useTaxConfigurations() {
  const hasTenantScope = useTaxConfigurationTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...taxConfigurationKeys.list()]),
    queryFn: () => taxConfigurationApi.list(),
    enabled: hasTenantScope,
  })
}

export function useTaxConfiguration(id: string) {
  const hasTenantScope = useTaxConfigurationTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...taxConfigurationKeys.detail(id)]),
    queryFn: () => taxConfigurationApi.get(id),
    enabled: !!id && hasTenantScope,
  })
}

export function useDocumentTypes() {
  const hasTenantScope = useTaxConfigurationTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...taxConfigurationKeys.documentTypes()]),
    queryFn: () => taxConfigurationApi.getDocumentTypes(),
    enabled: hasTenantScope,
    staleTime: Infinity, // Document types don't change often
  })
}

export function useTaxConfigurationCapabilities() {
  const hasTenantScope = useTaxConfigurationTenantScope()

  return useQuery({
    queryKey: tenantScopedKey([...taxConfigurationKeys.capabilities()]),
    queryFn: () => taxConfigurationApi.getCapabilities(),
    enabled: hasTenantScope,
    // Derived from company.country_code, which is immutable after creation.
    // The tenant/company-scoped key still separates newly created companies and
    // refetches when the active company changes, so this entry can remain static.
    staleTime: Infinity,
  })
}

export function useCreateTaxConfiguration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: TaxConfigurationFormData) =>
      taxConfigurationApi.create(data),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: [...taxConfigurationKeys.list()],
      })
    },
  })
}

export function useUpdateTaxConfiguration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<TaxConfigurationFormData> }) =>
      taxConfigurationApi.update(id, data),
    onSuccess: async (_, variables) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: [...taxConfigurationKeys.list()],
        }),
        queryClient.invalidateQueries({
          queryKey: [...taxConfigurationKeys.detail(variables.id)],
        }),
      ])
    },
  })
}

export function useDeleteTaxConfiguration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => taxConfigurationApi.delete(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: [...taxConfigurationKeys.list()],
      })
    },
  })
}

export function useReorderTaxConfigurations() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (order: { id: string; sequence_order: number }[]) =>
      taxConfigurationApi.reorder(order),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: [...taxConfigurationKeys.list()],
      })
    },
  })
}
