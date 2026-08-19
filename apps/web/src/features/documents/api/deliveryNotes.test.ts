import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApi = vi.hoisted(() => ({ get: vi.fn() }))

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
