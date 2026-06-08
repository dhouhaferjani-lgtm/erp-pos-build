import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { StockTransferListPage } from '../pages/StockTransferListPage'
import type { StockTransfer } from '../types'

const usedKeys: string[] = []

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      usedKeys.push(key)
      return key
    },
  }),
}))

const mockUseList = vi.fn()

vi.mock('../api/queries', () => ({
  useStockTransferList: (...args: unknown[]): unknown => mockUseList(...args) as unknown,
}))

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <StockTransferListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

const transferFixture: StockTransfer = {
  id: 'tr-1',
  transfer_number: 'TR-2026-00001',
  transfer_type: 'intracompany',
  status: 'in_transit',
  source_location_id: 'loc-a',
  source_location_name: 'Main Warehouse',
  destination_location_id: 'loc-b',
  destination_location_name: 'Downtown Shop',
  notes: null,
  transfer_cost: '0.0000',
  transfer_cost_label: null,
  transfer_cost_distribution: 'pro_rata_value',
  initiated_by_user_id: 'u-1',
  initiated_by_name: 'Alice',
  completed_by_user_id: null,
  completed_by_name: null,
  cancelled_by_user_id: null,
  cancelled_by_name: null,
  initiated_at: '2026-05-28T10:00:00Z',
  completed_at: null,
  cancelled_at: null,
  cancellation_reason: null,
  created_at: '2026-05-28T09:00:00Z',
  updated_at: '2026-05-28T10:00:00Z',
}

describe('StockTransferListPage', () => {
  beforeEach(() => {
    usedKeys.length = 0
  })

  it('renders the transfer rows from the query result', () => {
    mockUseList.mockReturnValue({
      data: {
        data: [transferFixture],
        meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      },
      isLoading: false,
    })

    renderPage()

    expect(screen.getByText('TR-2026-00001')).toBeInTheDocument()
    expect(screen.getByText('Main Warehouse')).toBeInTheDocument()
    expect(screen.getByText('Downtown Shop')).toBeInTheDocument()
    expect(usedKeys).toContain('title')
    expect(usedKeys).toContain('list.transferNumber')
  })

  it('shows the empty state when there are no transfers', () => {
    mockUseList.mockReturnValue({
      data: {
        data: [],
        meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 },
      },
      isLoading: false,
    })

    renderPage()

    expect(usedKeys).toContain('noTransfers')
    expect(usedKeys).toContain('noTransfersHint')
  })
})
