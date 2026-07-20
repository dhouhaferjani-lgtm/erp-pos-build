import type { ReplenishmentLine } from '../types'

export function makeReplenishmentLine(overrides: Partial<ReplenishmentLine> = {}): ReplenishmentLine {
  return {
    id: 'request-1',
    location_id: 'shop-a',
    location_name: 'Shop A',
    product_id: 'product-1',
    product_name: 'Serum',
    variant_id: null,
    variant_name: null,
    requested_qty: null,
    note: null,
    request_count: 1,
    status: 'pending',
    source_channel: 'web',
    first_requested_at: '2026-07-10T08:00:00Z',
    last_requested_at: '2026-07-10T09:00:00Z',
    sourcing_document_id: null,
    fulfillment_type: null,
    fulfillment_id: null,
    rejection_reason: null,
    quantity_decimals: 4,
    ...overrides,
  }
}
