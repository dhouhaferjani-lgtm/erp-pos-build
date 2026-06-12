import { fireEvent, render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AggregateChannelOrderRow, AggregateChannelOrdersResponse } from '../types'
import { EcommerceOrdersPage } from './EcommerceOrdersPage'

const { mockUseAggregateChannelOrders } = vi.hoisted(() => ({
  mockUseAggregateChannelOrders: vi.fn(),
}))

vi.mock('../hooks/useAggregateChannelOrders', () => ({
  useAggregateChannelOrders: mockUseAggregateChannelOrders,
}))

function makeRow(overrides: Partial<AggregateChannelOrderRow> = {}): AggregateChannelOrderRow {
  return {
    id: '2f6a1c1e-9b3d-4a8e-9c0a-111111111111',
    channel_id: '3a7b2d2f-8c4e-4b9f-8d1b-222222222222',
    external_order_id: 'EXT-1001',
    received_at: '2026-06-10T09:30:00Z',
    processed_at: null,
    status: 'pending',
    error_message: null,
    payload: { customer_name: 'Amel Ben Salah', total: '120.500' },
    document_id: null,
    channel: { id: '3a7b2d2f-8c4e-4b9f-8d1b-222222222222', name: 'Main Web Store' },
    ...overrides,
  }
}

function queryResult(response: AggregateChannelOrdersResponse | undefined, extra: { isLoading?: boolean; error?: Error | null } = {}) {
  return {
    data: response,
    isLoading: extra.isLoading ?? false,
    error: extra.error ?? null,
  }
}

const defaultResponse: AggregateChannelOrdersResponse = {
  data: [
    makeRow(),
    makeRow({
      id: '4c8d3e3a-7d5f-4caf-9e2c-333333333333',
      channel_id: '5d9e4f4b-6e6a-4dbf-af3d-444444444444',
      external_order_id: 'EXT-2002',
      status: 'processed',
      payload: {},
      channel: { id: '5d9e4f4b-6e6a-4dbf-af3d-444444444444', name: 'Marketplace' },
    }),
  ],
  meta: { current_page: 1, last_page: 3, per_page: 25, total: 60 },
}

describe('EcommerceOrdersPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseAggregateChannelOrders.mockReturnValue(queryResult(defaultResponse))
  })

  it('renders order rows including the channel name per row', () => {
    render(<EcommerceOrdersPage />)

    expect(screen.getByText('EXT-1001')).toBeInTheDocument()
    expect(screen.getByText('EXT-2002')).toBeInTheDocument()
    expect(screen.getByText('Main Web Store')).toBeInTheDocument()
    expect(screen.getByText('Marketplace')).toBeInTheDocument()
    // Payload-derived best-effort columns
    expect(screen.getByText('Amel Ben Salah')).toBeInTheDocument()
    expect(screen.getByText('120.500')).toBeInTheDocument()
    // Status badges via translated enum labels (scoped to the table so the
    // filter <option> elements with the same labels don't collide)
    const table = screen.getByRole('table')
    expect(within(table).getByText('Pending')).toBeInTheDocument()
    expect(within(table).getByText('Processed')).toBeInTheDocument()
  })

  it('passes the selected status filter to the hook and resets to page 1', () => {
    render(<EcommerceOrdersPage />)

    const select = screen.getByRole('combobox', { name: /filter by status/i })
    fireEvent.change(select, { target: { value: 'failed' } })

    expect(mockUseAggregateChannelOrders).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'failed', page: 1, per_page: 25 }),
    )
  })

  it('drives the pager from pagination meta and requests the next page', () => {
    render(<EcommerceOrdersPage />)

    const previous = screen.getByRole('button', { name: /previous/i })
    const next = screen.getByRole('button', { name: /next/i })
    expect(previous).toBeDisabled()
    expect(next).toBeEnabled()

    fireEvent.click(next)

    expect(mockUseAggregateChannelOrders).toHaveBeenLastCalledWith(
      expect.objectContaining({ page: 2, per_page: 25 }),
    )
  })

  it('hides the pager when there is a single page', () => {
    mockUseAggregateChannelOrders.mockReturnValue(
      queryResult({
        data: [makeRow()],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
      }),
    )

    render(<EcommerceOrdersPage />)

    expect(screen.queryByRole('button', { name: /next/i })).not.toBeInTheDocument()
  })

  it('renders the empty state when no orders exist', () => {
    mockUseAggregateChannelOrders.mockReturnValue(
      queryResult({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
      }),
    )

    render(<EcommerceOrdersPage />)

    expect(screen.getByText(/no channel orders received yet/i)).toBeInTheDocument()
  })

  it('renders loading and error states', () => {
    mockUseAggregateChannelOrders.mockReturnValue(queryResult(undefined, { isLoading: true }))
    const { unmount } = render(<EcommerceOrdersPage />)
    expect(screen.getByText(/loading/i)).toBeInTheDocument()
    unmount()

    mockUseAggregateChannelOrders.mockReturnValue(queryResult(undefined, { error: new Error('boom') }))
    render(<EcommerceOrdersPage />)
    expect(screen.getByText(/failed to load/i)).toBeInTheDocument()
  })
})
