import { api } from '../../../lib/api'

export interface ChainVerificationResult {
  terminal_code: string
  terminal_id: string
  receipt_chain: {
    is_valid: boolean
    chain_length: number
    first_receipt: string | null
    last_receipt: string | null
    broken_at_sequence: number | null
    verified_at: string
  }
  z_report_chain: {
    is_valid: boolean
    chain_length: number
    first_z_number: number | null
    last_z_number: number | null
    broken_at_z_number: number | null
    verified_at: string
  }
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
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
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
 * Returns per-terminal verification results.
 */
export async function verifyChains(companyId: string): Promise<ChainVerificationResult[]> {
  const response = await api.post('/compliance/nf525/verify-chains', { company_id: companyId })
  return (response.data as { data: ChainVerificationResult[] }).data
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
