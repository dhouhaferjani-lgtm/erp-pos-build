import { describe, it, expect, vi, beforeEach } from 'vitest'
import { verifyChains } from './complianceApi'
import { api } from '../../../lib/api'

vi.mock('../../../lib/api', () => ({
  api: {
    post: vi.fn(),
    get: vi.fn(),
  },
}))

const mockedPost = vi.mocked(api.post)

// The EXACT shape returned by Nf525ExportController::verifyChains — the whole
// payload is nested under `data`, and the terminal list lives at `data.terminals`.
// axios exposes the HTTP body as `response.data`, so `response.data.data.terminals`.
function realResponse() {
  return {
    data: {
      data: {
        company_id: 'company-1',
        terminals: [
          {
            terminal_id: 't-1',
            terminal_code: 'CAISSE-01',
            terminal_name: 'Caisse 1',
            receipt_chain: {
              is_valid: true,
              total_receipts: 42,
              verified: 42,
              failed_at_sequence: null,
              error: null,
            },
            z_report_chain: {
              is_valid: true,
              total_reports: 5,
              verified: 5,
              failed_at_z_number: null,
              error: null,
            },
            is_valid: true,
          },
        ],
        all_chains_valid: true,
        verified_at: '2026-09-04T10:00:00+00:00',
      },
    },
  }
}

describe('verifyChains', () => {
  beforeEach(() => {
    mockedPost.mockReset()
  })

  it('extracts the terminals array from the nested response.data.data.terminals', async () => {
    mockedPost.mockResolvedValueOnce(realResponse())

    const result = await verifyChains()

    expect(Array.isArray(result.terminals)).toBe(true)
    expect(result.terminals).toHaveLength(1)
    expect(result.terminals[0].terminal_code).toBe('CAISSE-01')
    expect(result.terminals[0].receipt_chain.total_receipts).toBe(42)
  })

  it('preserves the audit fields of the envelope (all_chains_valid + verified_at)', async () => {
    mockedPost.mockResolvedValueOnce(realResponse())

    const result = await verifyChains()

    expect(result.all_chains_valid).toBe(true)
    expect(result.verified_at).toBe('2026-09-04T10:00:00+00:00')
    expect(result.company_id).toBe('company-1')
  })

  it('sends NO company_id in the body — the controller resolves the company from CompanyContext', async () => {
    // Nf525ExportController::verifyChains uses `$this->companyContext->requireCompanyId()`
    // and the class docblock states "Body `company_id` is no longer accepted"
    // (it was a cross-tenant exfiltration vector). Sending one implied the
    // client picks the tenant scope.
    mockedPost.mockResolvedValueOnce(realResponse())

    await verifyChains()

    expect(mockedPost).toHaveBeenCalledWith('/compliance/nf525/verify-chains', {})
  })

  it('degrades gracefully to an empty terminal list when the payload is unexpected', async () => {
    mockedPost.mockResolvedValueOnce({ data: { data: { all_chains_valid: true } } })

    const result = await verifyChains()

    expect(result.terminals).toEqual([])
    expect(result.verified_at).toBe('')
  })

  it('degrades gracefully when the body is empty/null', async () => {
    mockedPost.mockResolvedValueOnce({ data: null })

    const result = await verifyChains()

    expect(result.terminals).toEqual([])
    expect(result.all_chains_valid).toBe(false)
  })
})
