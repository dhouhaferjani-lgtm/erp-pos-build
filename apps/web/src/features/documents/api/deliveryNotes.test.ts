import { describe, expect, it, vi } from 'vitest'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
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
