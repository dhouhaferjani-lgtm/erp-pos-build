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

    const result = await verifyChains('company-1')

    expect(Array.isArray(result)).toBe(true)
    expect(result).toHaveLength(1)
    expect(result[0].terminal_code).toBe('CAISSE-01')
    expect(result[0].receipt_chain.total_receipts).toBe(42)
  })

  it('degrades gracefully to an empty array when the payload is unexpected', async () => {
    mockedPost.mockResolvedValueOnce({ data: { data: { all_chains_valid: true } } })
    await expect(verifyChains('company-1')).resolves.toEqual([])
  })

  it('degrades gracefully to an empty array when the body is empty/null', async () => {
    mockedPost.mockResolvedValueOnce({ data: null })
    await expect(verifyChains('company-1')).resolves.toEqual([])
  })
})
