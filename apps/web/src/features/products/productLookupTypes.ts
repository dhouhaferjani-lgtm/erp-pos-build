export interface PlatformProductData {
  id: string
  barcode: string
  name: string
  brand: string | null
  description: string | null
  classification: Record<string, unknown>
  ingredients: { name: string; position: number }[]
  images: { url: string | null; thumbnail: string | null; type: string | null }[]
  confidenceScore: number
  enrichmentTier: string | null
}

export interface SuggestedProduct {
  name: string
  barcode: string
  brand: string | null
  /** Local ERP brand resolved through the cross-ERP mapping ladder. */
  brand_id: string | null
  description: string | null
  platform_product_id: string
  classification: Record<string, unknown>
  ingredients: { name: string; position: number }[]
  images: { url: string | null; thumbnail: string | null; type: string | null }[]
}

export interface CatalogLookupResult {
  status: 'found' | 'not_found' | 'error'
  barcode: string | null
  product: PlatformProductData | null
  trackingId: string | null
  suggestedProduct: SuggestedProduct | null
  errorReason: string | null
}

export interface SubmissionResult {
  trackingId: string
  status: string
  statusUrl: string
}

export type LookupState = 'idle' | 'searching' | 'found' | 'not_found' | 'error'
