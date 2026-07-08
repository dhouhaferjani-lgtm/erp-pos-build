export type DocumentKind = App.Modules.DocumentIngestion.Domain.Enums.DocumentKind
export type IngestionStatus = App.Modules.DocumentIngestion.Domain.Enums.IngestionStatus

export interface ExtractedField {
  value: string
  confidence: number
  sourceBbox: readonly unknown[] | null
}

export interface ExtractedLine {
  description: ExtractedField
  supplierRef: ExtractedField | null
  quantity: ExtractedField
  unitPrice: ExtractedField | null
  taxRate: ExtractedField | null
  lineTotal: ExtractedField | null
  batchNumber: ExtractedField | null
  expiryDate: ExtractedField | null
}

export interface ExtractionResult {
  docKind: string
  pages: number
  supplier: Record<string, ExtractedField>
  header: Record<string, ExtractedField>
  lines: ExtractedLine[]
  totalsConsistent: boolean
}

export interface ReconciliationSummary {
  consistent: boolean
  flags: string[]
}

export interface ConfidenceSummary {
  averageConfidence: number | null
  lowConfidenceFields: string[]
  reconciliation: ReconciliationSummary
}

export interface SupplierCandidate {
  id: string
  name: string
  vat?: string | null
  score?: string | null
}

export interface ProductCandidate {
  id: string
  name: string
  sku?: string | null
  requires_batch_tracking?: boolean
  requiresBatchTracking?: boolean
  tax_rate?: string | null
  taxRate?: string | null
}

export interface ReceiptLineCandidate {
  po_line_id?: string
  poLineId?: string
  receipt_line_id?: string
  receiptLineId?: string
  label?: string | null
  uninvoiced_qty?: string | null
  uninvoicedQty?: string | null
  unit_price?: string | null
  unitPrice?: string | null
}

export interface Suggestions {
  supplierCandidates: SupplierCandidate[]
  productCandidates: ProductCandidate[][]
  purchaseOrderCandidates: readonly unknown[]
  receiptLineCandidates: ReceiptLineCandidate[]
}

export interface DocumentIngestionSummary {
  id: string
  kind: DocumentKind
  status: IngestionStatus
  provider: string | null
  provider_model?: string | null
  providerModel?: string | null
  confidence_summary?: ConfidenceSummary | null
  confidenceSummary?: ConfidenceSummary | null
  committed_type?: string | null
  committedType?: string | null
  committed_id?: string | null
  committedId?: string | null
  created_at?: string | null
  createdAt?: string | null
}

export interface DocumentIngestionDetail extends DocumentIngestionSummary {
  source_url?: string | null
  sourceUrl?: string | null
  extraction: ExtractionResult | null
  suggestions: Suggestions | null
  error?: { code?: string; message?: string } | null
}

export interface DocumentIngestionListMeta {
  current_page?: number
  last_page?: number
  per_page?: number
  total?: number
}

export interface DocumentIngestionListResponse {
  data: DocumentIngestionSummary[]
  meta?: DocumentIngestionListMeta
}

export interface UploadDocumentIngestionInput {
  kind: DocumentKind
  file: File
}

export interface ReviewedBatchPayload {
  batch_number: string
  expiry_date: string
}

export interface ReviewedLinePayload {
  productId: string
  variantId?: string
  quantity: string
  unitPrice?: string
  vatRate?: string
  freeQuantity?: string
  batch?: ReviewedBatchPayload
  sourceLineId?: string
}

export interface ReviewedPayload {
  supplierId: string
  locationId?: string
  reference?: string
  documentDate?: string
  currency?: string
  pendingReceipt?: boolean
  lines: ReviewedLinePayload[]
}

export interface CommitResult {
  committedType: 'goods_receipt' | 'supplier_invoice' | string
  committedId: string
  goodsReceiptNumber?: string | null
}

export interface LocationOption {
  id: string
  name: string
}
