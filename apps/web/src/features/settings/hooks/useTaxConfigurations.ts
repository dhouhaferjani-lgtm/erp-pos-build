import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { taxConfigurationApi } from '../api/taxConfigurationApi'
import type { TaxConfigurationFormData } from '../types/tax'

export const taxConfigurationKeys = {
  all: ['tax-configurations'] as const,
  list: () => [...taxConfigurationKeys.all, 'list'] as const,
  detail: (id: string) => [...taxConfigurationKeys.all, 'detail', id] as const,
  documentTypes: () => [...taxConfigurationKeys.all, 'document-types'] as const,
}

export function useTaxConfigurations() {
  return useQuery({
    queryKey: taxConfigurationKeys.list(),
    queryFn: () => taxConfigurationApi.list(),
  })
}

export function useTaxConfiguration(id: string) {
  return useQuery({
    queryKey: taxConfigurationKeys.detail(id),
    queryFn: () => taxConfigurationApi.get(id),
    enabled: !!id,
  })
}

export function useDocumentTypes() {
  return useQuery({
    queryKey: taxConfigurationKeys.documentTypes(),
    queryFn: () => taxConfigurationApi.getDocumentTypes(),
    staleTime: Infinity, // Document types don't change often
  })
}

export function useCreateTaxConfiguration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: TaxConfigurationFormData) =>
      taxConfigurationApi.create(data),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() })
    },
  })
}

export function useUpdateTaxConfiguration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<TaxConfigurationFormData> }) =>
      taxConfigurationApi.update(id, data),
    onSuccess: (_, variables) => {
      void queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() })
      void queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.detail(variables.id) })
    },
  })
}

export function useDeleteTaxConfiguration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => taxConfigurationApi.delete(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() })
    },
  })
}

export function useReorderTaxConfigurations() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (order: { id: string; sequence_order: number }[]) =>
      taxConfigurationApi.reorder(order),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() })
    },
  })
}
