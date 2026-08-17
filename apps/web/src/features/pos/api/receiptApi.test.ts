import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchReceiptFilterOptions, fetchReceipts } from './receiptApi'

const { getMock } = vi.hoisted(() => ({
  getMock: vi.fn<(url: string, config?: unknown) => Promise<{ data: unknown }>>(),
}))

vi.mock('@/lib/api', () => ({
  api: { get: getMock },
  apiGet: vi.fn(),
}))

describe('receipt reporting API', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('serializes the SALE register as one location-scoped request', async () => {
    getMock.mockResolvedValue({
      data: { data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null } } },
    })

    await fetchReceipts({
      location_ids: ['loc-b', 'loc-a'],
      invoice_type_codes: ['SALE', 'TRAINING'],
      include_training: true,
      receipt_number: 'R-1',
      from_date: '2026-08-17',
      to_date: '2026-08-17',
      page: 2,
      per_page: 50,
    })

    const url = getMock.mock.calls[0]?.[0]
    expect(typeof url).toBe('string')
    if (typeof url !== 'string') throw new Error('Expected receipt request URL')
    expect(url).toContain('/pos/receipts?')
    expect(url).toContain('location_ids%5B%5D=loc-b')
    expect(url).toContain('location_ids%5B%5D=loc-a')
    expect(url).toContain('invoice_type_codes%5B%5D=SALE')
    expect(url).toContain('invoice_type_codes%5B%5D=TRAINING')
    expect(url).toContain('include_training=true')
    expect(url).not.toContain('REFUND')
    expect(url).not.toContain('VOID')
  })

  it('requests filter options with location and calendar bounds only', async () => {
    getMock.mockResolvedValue({ data: { data: { terminals: [], cashiers: [] } } })

    await fetchReceiptFilterOptions({
      location_ids: ['loc-a'],
      from_date: '2026-08-17',
      to_date: '2026-08-17',
    })

    expect(getMock.mock.calls[0]?.[0]).toBe(
      '/pos/receipts/filter-options?location_ids%5B%5D=loc-a&from_date=2026-08-17&to_date=2026-08-17',
    )
  })
})
