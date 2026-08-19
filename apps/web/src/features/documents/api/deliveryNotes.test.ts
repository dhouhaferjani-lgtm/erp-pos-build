import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApi = vi.hoisted(() => ({ get: vi.fn() }))

beforeEach(() => {
  vi.clearAllMocks()
})

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: mockApi,
    apiGet: mockApiGet,
  }
})

describe('getInvoiceableDeliveryNotes', () => {
  it('asks the server for confirmed uninvoiced delivery notes instead of filtering a client payload mirror', async () => {
    mockApiGet.mockResolvedValue([])

    const { getInvoiceableDeliveryNotes } = await import('./deliveryNotes')
    await getInvoiceableDeliveryNotes('partner-42')

    expect(mockApiGet).toHaveBeenCalledWith('/delivery-notes', {
      status: 'confirmed',
      partner_id: 'partner-42',
      uninvoiced: 1,
    })
  })
})

describe('getPartnerDeliveryNotes', () => {
  it('requests an offset page and keeps billing aggregates in the server envelope', async () => {
    const response = {
      data: {
        data: [],
        meta: { current_page: 2, last_page: 3, total: 21, per_page: 10, from: 11, to: 20 },
        aggregates: { count: 21, total: '1240.500', currency: 'TND' },
      },
    }
    mockApi.get.mockResolvedValue(response)

    const { getPartnerDeliveryNotes } = await import('./deliveryNotes')
    await expect(getPartnerDeliveryNotes({
      partnerId: 'partner-42',
      filter: 'uninvoiced',
      page: 2,
      perPage: 10,
    })).resolves.toEqual(response.data)

    expect(mockApi.get).toHaveBeenCalledWith('/delivery-notes', {
      params: {
        partner_id: 'partner-42',
        status: 'confirmed',
        uninvoiced: 1,
        page: 2,
        per_page: 10,
        with_aggregates: 1,
      },
    })
  })

  it('uses the generated DocumentData contract instead of declaring a delivery-note mirror', () => {
    const source = readFileSync(
      resolve(process.cwd(), 'src/features/documents/api/deliveryNotes.ts'),
      'utf8',
    )

    expect(source).toContain('Pick<App.Modules.Document.Application.DTOs.DocumentData')
    expect(source).not.toMatch(/(?:interface|type)\s+DeliveryNote\s*=\s*\{/)
  })
})

describe('to-bill queue API', () => {
  const params = {
    locationId: 'location-7',
    partnerSearch: 'atlas',
    dateFrom: '2026-07-01',
    dateTo: '2026-08-10',
    periodicOnly: true,
    page: 2,
    perPage: 25,
  }

  it('serializes the queue filters for both summary and partner rows', async () => {
    mockApiGet.mockResolvedValue({ data: [] })

    const { getToBillPartnerRows, getToBillQueue } = await import('./deliveryNotes')
    await getToBillQueue(params)
    await getToBillPartnerRows('partner-42', params)

    const expectedParams = {
      location_id: 'location-7',
      partner_search: 'atlas',
      date_from: '2026-07-01',
      date_to: '2026-08-10',
      periodic_only: 1,
      page: 2,
      per_page: 25,
    }
    expect(mockApiGet).toHaveBeenNthCalledWith(1, '/delivery-notes/uninvoiced', expectedParams)
    expect(mockApiGet).toHaveBeenNthCalledWith(
      2,
      '/delivery-notes/uninvoiced/partner-42',
      expectedParams,
    )
  })

  it('loads every partner row page before creating an invoice', async () => {
    mockApiGet
      .mockResolvedValueOnce({
        data: [{ id: 'dn-1' }],
        meta: { current_page: 1, last_page: 2, total: 2, per_page: 100 },
      })
      .mockResolvedValueOnce({
        data: [{ id: 'dn-2' }],
        meta: { current_page: 2, last_page: 2, total: 2, per_page: 100 },
      })

    const { getAllToBillPartnerRows } = await import('./deliveryNotes')
    await expect(getAllToBillPartnerRows('partner-42', params)).resolves.toEqual([
      { id: 'dn-1' },
      { id: 'dn-2' },
    ])
    expect(mockApiGet).toHaveBeenNthCalledWith(
      2,
      '/delivery-notes/uninvoiced/partner-42',
      expect.objectContaining({ page: 2, per_page: 100 }),
    )
  })
})
