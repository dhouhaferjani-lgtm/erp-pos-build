import { apiPost } from '@/lib/api'
import type { CatalogLookupResult, SubmissionResult } from '../types/platform'

export async function lookupBarcode(barcode: string): Promise<CatalogLookupResult> {
  return apiPost<CatalogLookupResult>('/platform/barcode-lookup', { barcode })
}

export interface SubmitForEnrichmentPayload {
  barcode: string | null
  name: string
  brand: string
  category?: string
  description?: string
}

export async function submitForEnrichment(data: SubmitForEnrichmentPayload): Promise<SubmissionResult> {
  return apiPost<SubmissionResult>('/platform/submit-for-enrichment', data)
}
