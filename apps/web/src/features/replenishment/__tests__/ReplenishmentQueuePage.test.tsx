import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReplenishmentLine } from '../types'
import { ReplenishmentQueuePage } from '../pages/ReplenishmentQueuePage'

const openQuery = vi.fn<(filters: unknown) => unknown>()
const historyQuery = vi.fn<(filters: unknown) => unknown>()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../api/queries', () => ({
  useOpenReplenishment: (filters: unknown) => openQuery(filters),
  useReplenishmentHistory: (filters: unknown) => historyQuery(filters),
}))

vi.mock('@/features/locations/components/LocationSelectorMulti', () => ({
  LocationSelectorMulti: () => <div data-testid="location-filter" />,
}))

vi.mock('@/components/ui/filters/DateRangeFilter', () => ({
  DateRangeFilter: () => <div data-testid="date-filter" />,
}))

vi.mock('@/components/auth', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => children,
}))

vi.mock('../components/RequestContextPanel', () => ({
  RequestContextPanel: ({ line }: { line: ReplenishmentLine }) => (
    <div data-testid="request-context">{line.product_name}</div>
  ),
}))

const lines: ReplenishmentLine[] = [
  makeLine('request-1', 'shop-a', 'Shop A', 'product-1', 'Serum'),
  makeLine('request-2', 'shop-b', 'Shop B', 'product-1', 'Serum'),
  makeLine('request-3', 'shop-a', 'Shop A', 'product-2', 'Cream'),
  makeLine('request-4', 'shop-b', 'Shop B', 'product-2', 'Cream'),
]

function makeLine(
  id: string,
  locationId: string,
  locationName: string,
  productId: string,
  productName: string,
): ReplenishmentLine {
  return {
    id,
    location_id: locationId,
    location_name: locationName,
    product_id: productId,
    product_name: productName,
    variant_id: null,
    variant_name: null,
    requested_qty: id === 'request-1' ? null : '2.0000',
    note: id === 'request-1' ? 'Urgent' : null,
    request_count: id === 'request-2' ? 2 : 1,
    status: 'pending',
    source_channel: 'web',
    first_requested_at: '2026-07-10T08:00:00Z',
    last_requested_at: '2026-07-10T09:00:00Z',
    sourcing_document_id: null,
    fulfillment_type: null,
    fulfillment_id: null,
    rejection_reason: null,
    quantity_decimals: 4,
  }
}

describe('ReplenishmentQueuePage', () => {
  beforeEach(() => {
    openQuery.mockReturnValue({
      data: { data: lines, meta: { truncated: false } },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
    historyQuery.mockReturnValue({
      data: undefined,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
  })

  it('groups open requests into two shop sections', () => {
    render(<ReplenishmentQueuePage />)

    expect(screen.getAllByTestId('shop-group')).toHaveLength(2)
    expect(screen.getByRole('heading', { name: /Shop A/ })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /Shop B/ })).toBeInTheDocument()
  })

  it('pivots two products across one matrix column per shop and toggles selection', async () => {
    const user = userEvent.setup()
    render(<ReplenishmentQueuePage />)

    await user.click(screen.getByRole('button', { name: 'queue.by_product' }))

    expect(screen.getAllByTestId('matrix-shop')).toHaveLength(2)
    expect(screen.getAllByTestId('matrix-product')).toHaveLength(2)
    await user.click(screen.getByTestId('matrix-cell-request-1'))
    expect(screen.getByText('selection.count')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'actions.create_transfer' })).toBeInTheDocument()
    await user.click(screen.getByText('Serum'))
    expect(screen.getByTestId('request-context')).toHaveTextContent('Serum')
  })

  it('renders the requested quantity at the product unit precision', () => {
    openQuery.mockReturnValue({
      data: {
        data: [
          {
            ...makeLine('req-piece', 'shop-a', 'Shop A', 'product-piece', 'Widget'),
            requested_qty: '2.0000',
            quantity_decimals: 0,
          },
        ],
        meta: { truncated: false },
      },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
    render(<ReplenishmentQueuePage />)

    expect(screen.getByText('2')).toBeInTheDocument()
    expect(screen.queryByText('2.0000')).not.toBeInTheDocument()
  })

  it('renders the mandated empty and query-error states', () => {
    openQuery.mockReturnValueOnce({
      data: { data: [], meta: { truncated: false } },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
    const { rerender } = render(<ReplenishmentQueuePage />)
    expect(screen.getByText('queue.empty_title')).toBeInTheDocument()

    openQuery.mockReturnValue({
      data: undefined,
      isLoading: false,
      error: new Error('Queue failed'),
      refetch: vi.fn(),
    })
    rerender(<ReplenishmentQueuePage />)
    expect(screen.getByText('Queue failed')).toBeInTheDocument()
  })

  it('links fulfilled history rows to the fulfilling transfer or purchase order', async () => {
    const user = userEvent.setup()
    historyQuery.mockReturnValue({
      data: {
        data: [
          {
            ...makeLine('hist-transfer', 'shop-a', 'Shop A', 'product-1', 'Serum'),
            status: 'fulfilled',
            fulfillment_type: 'transfer',
            fulfillment_id: 'transfer-99',
          },
          {
            ...makeLine('hist-po', 'shop-b', 'Shop B', 'product-2', 'Cream'),
            status: 'fulfilled',
            fulfillment_type: 'purchase_order',
            fulfillment_id: 'po-42',
          },
        ],
        meta: { current_page: 1, last_page: 1, total: 2, per_page: 25, from: 1, to: 2 },
      },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(
      <MemoryRouter>
        <ReplenishmentQueuePage />
      </MemoryRouter>,
    )

    await user.click(screen.getByRole('button', { name: 'status.fulfilled' }))

    const transferLink = screen.getByRole('link', { name: 'history.view_transfer' })
    expect(transferLink).toHaveAttribute('href', '/inventory/stock-transfers/transfer-99')

    const poLink = screen.getByRole('link', { name: 'history.view_purchase_order' })
    expect(poLink).toHaveAttribute('href', '/purchases/orders/po-42')
  })
})
