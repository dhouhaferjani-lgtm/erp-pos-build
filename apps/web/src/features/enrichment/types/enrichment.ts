export interface EnrichedProductData {
  name: string
  brand: string | null
  description: string | null
  classification: Record<string, unknown>
  ingredients: string[]
  images: { url: string; type?: string }[]
  confidence_score: number
  enrichment_tier: string | null
  field_confidence: Record<string, number> | null
  enrichment_sources: string[] | null
  assigned_barcode: string | null
  assigned_barcode_type: string | null
}

export type EnrichmentResultOrigin = 'initial' | 'curated_update'
export type EnrichmentRejectionReason = 'wrong_product' | 'bad_data'

export interface EnrichmentResult {
  id: string
  product_id: string
  product_name: string
  product_barcode: string | null
  product_sku: string | null
  tracking_id: string
  status: 'pending_review' | 'accepted' | 'rejected'
  version: number
  origin: EnrichmentResultOrigin
  enriched_data: EnrichedProductData
  enrichment_quality: 'high' | 'medium' | 'low'
  assigned_barcode: string | null
  reviewed_at: string | null
  reviewed_by: string | null
  accepted_fields: Record<string, boolean> | null
  rejection_reason: string | null
  rejection_notes: string | null
  created_at: string
}

export interface EnrichmentResultsPage {
  data: EnrichmentResult[]
  meta: OffsetPaginationMeta & {
    timestamp: string
    request_id: string
  }
}

export interface ComparisonField {
  key: string
  label: string
  userValue: string | null
  enrichedValue: string | null
  confidence: number | null
  checked: boolean
}
import type { OffsetPaginationMeta } from '@/types/pagination'
