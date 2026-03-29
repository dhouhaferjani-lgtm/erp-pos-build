import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getEnrichmentResults,
  getEnrichmentResult,
  acceptEnrichmentResult,
  rejectEnrichmentResult,
  bulkAcceptEnrichmentResults,
} from './enrichmentApi'

export const enrichmentKeys = {
  all: ['enrichment-results'] as const,
  lists: () => [...enrichmentKeys.all, 'list'] as const,
  list: (params?: { status?: string; quality?: string; page?: number }) =>
    [...enrichmentKeys.lists(), params] as const,
  details: () => [...enrichmentKeys.all, 'detail'] as const,
  detail: (id: string) => [...enrichmentKeys.details(), id] as const,
}

export function useEnrichmentResults(params?: {
  status?: string
  quality?: string
  page?: number
}) {
  return useQuery({
    queryKey: enrichmentKeys.list(params),
    queryFn: () => getEnrichmentResults(params),
  })
}

export function useEnrichmentResult(id: string) {
  return useQuery({
    queryKey: enrichmentKeys.detail(id),
    queryFn: () => getEnrichmentResult(id),
    enabled: id.length > 0,
  })
}

export function useAcceptEnrichment() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, acceptedFields }: { id: string; acceptedFields: string[] }) =>
      acceptEnrichmentResult(id, acceptedFields),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: enrichmentKeys.all })
    },
  })
}

export function useRejectEnrichment() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) =>
      rejectEnrichmentResult(id, reason),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: enrichmentKeys.all })
    },
  })
}

export function useBulkAcceptEnrichment() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (ids: string[]) => bulkAcceptEnrichmentResults(ids),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: enrichmentKeys.all })
    },
  })
}
