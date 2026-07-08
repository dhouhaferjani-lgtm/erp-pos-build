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

export async function getDocumentIngestion(id: string): Promise<DocumentIngestionDetail> {
  const response = await api.get<ApiEnvelope<DocumentIngestionDetail>>(`/document-ingestions/${id}`)
  const detail = response.data.data
  return { ...detail, suggestions: normalizeSuggestions(detail.suggestions) }
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
