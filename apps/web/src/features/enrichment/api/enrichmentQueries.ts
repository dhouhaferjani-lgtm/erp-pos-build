import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  getEnrichmentResults,
  getEnrichmentResult,
  acceptEnrichmentResult,
  rejectEnrichmentResult,
  bulkAcceptEnrichmentResults,
} from './enrichmentApi'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import type { EnrichmentRejectionReason } from '../types/enrichment'

export const enrichmentKeys = {
  all: ['enrichment-results'] as const,
  lists: () => [...enrichmentKeys.all, 'list'] as const,
  list: (params?: { status?: string; quality?: string; page?: number }) =>
    [...enrichmentKeys.lists(), params] as const,
  details: () => [...enrichmentKeys.all, 'detail'] as const,
  detail: (id: string) => [...enrichmentKeys.details(), id] as const,
}

export function enrichmentInvalidationPredicate(
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === 'enrichment-results' &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function useEnrichmentResults(params?: {
  status?: string
  quality?: string
  page?: number
}) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...enrichmentKeys.list(params)]),
    queryFn: () => getEnrichmentResults(params),
    enabled: tenantId !== null && companyId !== null,
  })
}

export function useEnrichmentResult(id: string) {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return useQuery({
    queryKey: tenantScopedKey([...enrichmentKeys.detail(id)]),
    queryFn: () => getEnrichmentResult(id),
    enabled: id.length > 0 && tenantId !== null && companyId !== null,
  })
}

export function useAcceptEnrichment() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, acceptedFields }: { id: string; acceptedFields: string[] }) =>
      acceptEnrichmentResult(id, acceptedFields),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: enrichmentInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useRejectEnrichment() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({
      id,
      notes,
      reason,
    }: {
      id: string
      notes?: string
      reason: EnrichmentRejectionReason
    }) => rejectEnrichmentResult(id, reason, notes),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: enrichmentInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}

export function useBulkAcceptEnrichment() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (ids: string[]) => bulkAcceptEnrichmentResults(ids),
    onSuccess: async () => {
      await qc.invalidateQueries({
        predicate: enrichmentInvalidationPredicate(tenantId, companyId),
      })
    },
  })
}
