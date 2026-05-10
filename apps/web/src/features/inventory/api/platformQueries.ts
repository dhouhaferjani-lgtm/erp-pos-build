import { useQuery, useMutation } from '@tanstack/react-query'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { lookupBarcode, submitForEnrichment } from './platformApi'
import type { SubmitForEnrichmentPayload } from './platformApi'

export const platformKeys = {
  all: ['platform'] as const,
  catalogLookup: (barcode: string) => [...platformKeys.all, 'catalog-lookup', barcode] as const,
}

export function useCatalogLookup(barcode: string | null) {
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...platformKeys.catalogLookup(barcode ?? '')]),
    queryFn: () => lookupBarcode(barcode!),
    enabled: barcode !== null && barcode.length >= 8 && !!tenantId && !!companyId,
    staleTime: 60 * 60 * 1000,
    retry: false,
    refetchOnWindowFocus: false,
  })
}

export function useProductSubmission() {
  return useMutation({
    mutationFn: (data: SubmitForEnrichmentPayload) => submitForEnrichment(data),
  })
}
