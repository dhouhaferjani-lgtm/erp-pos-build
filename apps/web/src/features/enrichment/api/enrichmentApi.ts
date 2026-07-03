import { api } from '@/lib/api'
import type {
  EnrichmentRejectionReason,
  EnrichmentResult,
  EnrichmentResultsPage,
} from '../types/enrichment'

export async function getEnrichmentResults(params?: {
  status?: string
  quality?: string
  page?: number
}): Promise<EnrichmentResultsPage> {
  const response = await api.get<EnrichmentResultsPage>('/enrichment-results', { params })
  return response.data
}

export async function getEnrichmentResult(id: string): Promise<EnrichmentResult> {
  const response = await api.get<{ data: EnrichmentResult }>(`/enrichment-results/${id}`)
  return response.data.data
}

export async function acceptEnrichmentResult(id: string, acceptedFields: string[]): Promise<void> {
  await api.post(`/enrichment-results/${id}/accept`, { accepted_fields: acceptedFields })
}

export async function rejectEnrichmentResult(
  id: string,
  reason: EnrichmentRejectionReason,
  notes?: string,
): Promise<void> {
  const trimmedNotes = notes?.trim()
  await api.post(`/enrichment-results/${id}/reject`, {
    reason,
    ...(trimmedNotes ? { notes: trimmedNotes } : {}),
  })
}

export async function bulkAcceptEnrichmentResults(ids: string[]): Promise<void> {
  await Promise.all(ids.map(id => acceptEnrichmentResult(id, ['name', 'brand', 'description', 'barcode'])))
}
