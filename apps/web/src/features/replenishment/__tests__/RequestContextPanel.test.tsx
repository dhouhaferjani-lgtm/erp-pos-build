import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { RequestContextPanel } from '../components/RequestContextPanel'
import type { ReplenishmentLine } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/features/products/api/productStock', () => ({
  getProductStock: vi.fn().mockResolvedValue({
    locations: [
      { location_id: 'shop-a', location_name: 'Shop A', available: '0.0000', min_quantity: '2.0000', max_quantity: '5.0000', is_below_minimum: true },
      { location_id: 'warehouse', location_name: 'Warehouse', available: '20.0000', min_quantity: '2.0000', max_quantity: '10.0000', is_below_minimum: false },
    ],
    totals: {},
  }),
}))

const line = {
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
} satisfies ReplenishmentLine

describe('RequestContextPanel', () => {
  it('renders the requesting location and surplus stock vector', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={client}>
        <RequestContextPanel line={line} onClose={vi.fn()} />
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Shop A')).toBeInTheDocument()
    expect(screen.getByText('Warehouse')).toBeInTheDocument()
    expect(screen.getByText('20.0000')).toBeInTheDocument()
  })
})
