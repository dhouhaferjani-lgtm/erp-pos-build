import { api } from '@/lib/api'
import type { EnrichmentResult, EnrichmentResultsPage } from '../types/enrichment'

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

export async function rejectEnrichmentResult(id: string, reason?: string): Promise<void> {
  await api.post(`/enrichment-results/${id}/reject`, { reason })
}

export async function bulkAcceptEnrichmentResults(ids: string[]): Promise<void> {
  await Promise.all(ids.map(id => acceptEnrichmentResult(id, ['name', 'brand', 'description', 'barcode'])))
}
