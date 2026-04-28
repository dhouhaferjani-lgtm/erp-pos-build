import { useQuery, useMutation } from '@tanstack/react-query'
import { lookupBarcode, submitForEnrichment } from './platformApi'
import type { SubmitForEnrichmentPayload } from './platformApi'

export const platformKeys = {
  all: ['platform'] as const,
  catalogLookup: (barcode: string) => [...platformKeys.all, 'catalog-lookup', barcode] as const,
}

export function useCatalogLookup(barcode: string | null) {
  return useQuery({
    queryKey: platformKeys.catalogLookup(barcode ?? ''),
    queryFn: () => lookupBarcode(barcode!),
    enabled: barcode !== null && barcode.length >= 8,
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
