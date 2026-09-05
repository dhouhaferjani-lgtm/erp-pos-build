import { api } from '../../../lib/api'
import type { OffsetPaginationMeta } from '@/types/pagination'

/**
 * Per-terminal chain verification result.
 *
 * Field names mirror the backend contract exactly
 * (Nf525ExportController::verifyChains) — do NOT rename them to FE-local
 * conventions; the panel renders directly off these keys.
 */
export interface ChainVerificationResult {
  terminal_id: string
  terminal_code: string
  terminal_name: string
  receipt_chain: {
    is_valid: boolean
    total_receipts: number
    verified: number
    failed_at_sequence: number | null
    error: string | null
  }
  z_report_chain: {
    is_valid: boolean
    total_reports: number
    verified: number
    failed_at_z_number: number | null
    error: string | null
  }
  is_valid: boolean
}

/**
 * Full verify-chains payload as returned under the top-level `data` envelope.
 */
export interface ChainVerificationResponse {
  company_id: string
  terminals: ChainVerificationResult[]
  all_chains_valid: boolean
  verified_at: string
}

export interface ReprintLogEntry {
  id: string
  receipt_id: string
  receipt_number: string
  terminal_code: string
  user_name: string
  print_type: 'original' | 'duplicate' | 'reprint'
  copy_number: number
  printed_at: string
  print_method: 'pdf' | 'thermal' | 'escpos'
}

export interface ReprintLogResponse {
  data: ReprintLogEntry[]
  meta: OffsetPaginationMeta
}

/**
 * Export NF525 JET XML for the given period.
 * Returns a Blob (XML file). Uses api.post directly since responseType is 'blob'.
 */
export async function exportJetXml(companyId: string, from: string, to: string): Promise<Blob> {
  const response = await api.post(
    '/compliance/nf525/export-jet',
    { company_id: companyId, from, to },
    { responseType: 'blob' }
  )
  return response.data as Blob
}

/**
 * Verify receipt and Z-report hash chains for all terminals.
 *
 * The backend nests the payload under the standard `data` envelope, and the
 * per-terminal rows live at `data.terminals` (NOT `data` directly). We read
 * `response.data.data.terminals` and defensively normalize to an array so an
 * unexpected/empty payload degrades gracefully instead of crashing the panel.
 */
export async function verifyChains(companyId: string): Promise<ChainVerificationResult[]> {
  const response = await api.post('/compliance/nf525/verify-chains', { company_id: companyId })
  const payload = (response.data as { data?: Partial<ChainVerificationResponse> } | null)?.data
  return Array.isArray(payload?.terminals) ? payload.terminals : []
}

/**
 * Get paginated reprint audit log.
 * Uses api.get directly (not apiGet) to preserve the meta wrapper for pagination.
 */
export async function getReprintLog(params: {
  company_id: string
  terminal_id?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
}): Promise<ReprintLogResponse> {
  const response = await api.get('/compliance/nf525/reprint-log', { params })
  return response.data as ReprintLogResponse
}
