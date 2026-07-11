import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
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
})
