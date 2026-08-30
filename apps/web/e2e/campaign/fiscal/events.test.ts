import { describe, expect, it } from 'vitest'
import { buildRefundEnvelope, buildSaleEnvelope } from './events'

const coordinates = {
  businessDate: '2026-08-29',
  companyId: '22222222-2222-4222-8222-222222222222',
  countryCode: 'TN',
  currencyCode: 'TND',
  currencyScale: 3,
  eventTimeDevice: '2026-08-29T10:00:00Z',
  genesisSeed: 'a'.repeat(64),
  methodCode: 'CASH',
  operatorId: '33333333-3333-4333-8333-333333333333',
  productId: '44444444-4444-4444-8444-444444444444',
  productName: 'Campaign product',
  productSku: 'CAMPAIGN-SKU',
  shiftId: '66666666-6666-4666-8666-666666666666',
  tenantId: '11111111-1111-4111-8111-111111111111',
  terminalId: '55555555-5555-4555-8555-555555555555',
}

describe('campaign receipt envelope authoring', () => {
  it('threads one shift through the sale and refund and exposes both chain sequences', async () => {
    const sale = await buildSaleEnvelope(coordinates)
    const refund = await buildRefundEnvelope({
      ...coordinates,
      eventTimeDevice: '2026-08-29T10:05:00Z',
      originalEventId: sale.eventId,
      originalReceiptUuid: sale.receiptUuid,
      previousHash: sale.currentHash,
    })

    expect(sale.payload['shift_id']).toBe(coordinates.shiftId)
    expect(sale.sequenceNumber).toBe(1)
    expect(refund.payload['shift_id']).toBe(coordinates.shiftId)
    expect(refund.sequenceNumber).toBe(2)
  })
})
