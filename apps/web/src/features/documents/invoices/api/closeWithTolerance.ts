import { api } from '@/lib/api'

export interface CloseWithToleranceMeta {
  tolerance_writeoff: {
    amount: string
    gl_entry_id: string
  }
  timestamp: string
}

export interface CloseWithToleranceResponse {
  data: unknown
  meta: CloseWithToleranceMeta
}

export type CloseWithToleranceErrorCode = 'TOLERANCE_EXCEEDED' | 'ALREADY_PAID' | (string & {})

export interface CloseWithToleranceErrorBody {
  error: {
    code: CloseWithToleranceErrorCode
    message: string
    details?: Record<string, unknown>
  }
}

/**
 * Closes a B2B invoice within payment tolerance.
 *
 * Skips the apiPost helper because we want the meta envelope (tolerance write-off
 * amount + GL entry id), which apiPost would strip out by unwrapping `data.data`.
 */
export async function closeInvoiceWithTolerance(
  invoiceId: string
): Promise<CloseWithToleranceResponse> {
  const response = await api.post<CloseWithToleranceResponse>(
    `/invoices/${invoiceId}/close-with-tolerance`,
    {}
  )
  return response.data
}
