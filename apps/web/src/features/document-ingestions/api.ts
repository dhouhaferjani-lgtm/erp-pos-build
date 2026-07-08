import type { AxiosError } from 'axios'
import { api, apiPost } from '@/lib/api'
import type {
  CommitResult,
  DocumentIngestionDetail,
  DocumentIngestionListResponse,
  DocumentIngestionSummary,
  IngestionStatus,
  ReviewedPayload,
  UploadDocumentIngestionInput,
} from './types'

interface ApiEnvelope<T> {
  data: T
}

export interface ListDocumentIngestionsParams {
  status?: IngestionStatus | ''
  kind?: string
  page?: number
}

export async function listDocumentIngestions(
  params: ListDocumentIngestionsParams = {},
): Promise<DocumentIngestionListResponse> {
  const response = await api.get<DocumentIngestionListResponse>('/document-ingestions', {
    params: Object.fromEntries(
      Object.entries(params).filter(([, value]) => value !== undefined && value !== ''),
    ),
  })
  return response.data
}

// The server serves MatchSuggestionService::toArray() verbatim — snake_case keys
// (supplier_candidates, product_candidates, …). The review page reads the camelCase
// shape; normalize once here so an undefined array can't crash the mount effect.
function normalizeSuggestions(raw: unknown): DocumentIngestionDetail['suggestions'] {
  if (raw === null || typeof raw !== 'object') {
    return null
  }
  const s = raw as Record<string, unknown>
  return {
    supplierCandidates: (s['supplierCandidates'] ?? s['supplier_candidates'] ?? []) as never,
    productCandidates: (s['productCandidates'] ?? s['product_candidates'] ?? []) as never,
    receiptLineCandidates: (s['receiptLineCandidates'] ?? s['receipt_line_candidates'] ?? []) as never,
    purchaseOrderCandidates: (s['purchaseOrderCandidates'] ?? s['purchase_order_candidates'] ?? []) as never,
  }
}

// The extraction JSONB is served verbatim too — its per-line fields are snake_case
// (unit_price, tax_rate, supplier_ref, line_total, batch_number, expiry_date), but the
// review form reads the camelCase ExtractedLine shape. Only `quantity`/`description`
// share a key, so the rest silently prefilled to '' (and the invoice commit shipped no
// per-line price). Map once here, tolerant of either casing.
function normalizeExtraction(raw: unknown): DocumentIngestionDetail['extraction'] {
  if (raw === null || typeof raw !== 'object') {
    return raw as DocumentIngestionDetail['extraction']
  }
  const extraction = raw as Record<string, unknown>
  const lines = Array.isArray(extraction['lines']) ? extraction['lines'] : []

  return {
    ...(extraction as object),
    lines: lines.map((rawLine) => {
      const line = (rawLine ?? {}) as Record<string, unknown>
      const pick = (camel: string, snake: string): unknown => line[camel] ?? line[snake] ?? null
      return {
        description: line['description'],
        supplierRef: pick('supplierRef', 'supplier_ref'),
        quantity: line['quantity'],
        unitPrice: pick('unitPrice', 'unit_price'),
        taxRate: pick('taxRate', 'tax_rate'),
        lineTotal: pick('lineTotal', 'line_total'),
        batchNumber: pick('batchNumber', 'batch_number'),
        expiryDate: pick('expiryDate', 'expiry_date'),
      }
    }),
  } as DocumentIngestionDetail['extraction']
}

export async function getDocumentIngestion(id: string): Promise<DocumentIngestionDetail> {
  const response = await api.get<ApiEnvelope<DocumentIngestionDetail>>(`/document-ingestions/${id}`)
  const detail = response.data.data
  return {
    ...detail,
    extraction: normalizeExtraction(detail.extraction),
    suggestions: normalizeSuggestions(detail.suggestions),
  }
}

export async function uploadDocumentIngestion(
  input: UploadDocumentIngestionInput,
): Promise<DocumentIngestionSummary> {
  const formData = new FormData()
  formData.append('kind', input.kind)
  formData.append('file', input.file)

  const response = await api.post<ApiEnvelope<DocumentIngestionSummary>>(
    '/document-ingestions',
    formData,
    {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    },
  )
  return response.data.data
}

export async function reExtractDocumentIngestion(id: string): Promise<DocumentIngestionDetail> {
  return apiPost<DocumentIngestionDetail>(`/document-ingestions/${id}/extract`, {})
}

export async function rejectDocumentIngestion(id: string): Promise<DocumentIngestionDetail> {
  return apiPost<DocumentIngestionDetail>(`/document-ingestions/${id}/reject`, {})
}

function isConflict(error: unknown): error is AxiosError {
  return (
    typeof error === 'object'
    && error !== null
    && 'response' in error
    && typeof error.response === 'object'
    && error.response !== null
    && 'status' in error.response
    && error.response.status === 409
  )
}

export async function commitDocumentIngestion(
  id: string,
  payload: ReviewedPayload,
): Promise<CommitResult> {
  try {
    return await apiPost<CommitResult>(`/document-ingestions/${id}/commit`, payload)
  } catch (error) {
    if (!isConflict(error)) {
      throw error
    }

    const refreshed = await getDocumentIngestion(id)
    const committedType = refreshed.committedType ?? refreshed.committed_type
    const committedId = refreshed.committedId ?? refreshed.committed_id
    if (committedType && committedId) {
      return {
        committedType,
        committedId,
        goodsReceiptNumber: null,
      }
    }

    throw error
  }
}
