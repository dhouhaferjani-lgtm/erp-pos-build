import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { LiveSalesFeed } from '../LiveSalesFeed'
import type { LiveSalesReport } from '../../api/ownerReportsApi'

const useLiveSalesMock = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { count?: number }) => (
      typeof options?.count === 'number' ? `${key}:${String(options.count)}` : key
    ),
  }),
}))

vi.mock('../../hooks/useOwnerReports', () => ({
  useLiveSales: useLiveSalesMock,
}))

const initialLiveSales: LiveSalesReport = {
  recent_receipts: [
    {
      id: 'receipt-1',
      posted_at: '2026-07-03T13:32:11Z',
      location_id: 'loc-1',
      location_name: 'Branche Lac 2',
      total: '86.400',
      currency: 'TND',
      items_count: 3,
      receipt_number: 'R-1001',
    },
  ],
  open_shifts_by_location: { 'loc-1': 2 },
  generated_at: '2026-07-03T13:32:15Z',
}

describe('LiveSalesFeed', () => {
  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders recent receipts with branch, formatted money, and item count', () => {
    useLiveSalesMock.mockReturnValue({ data: initialLiveSales, isLoading: false, isError: false })

    render(<LiveSalesFeed />)

    expect(screen.getByText('reports:ownerDashboard.liveSales.title')).toBeInTheDocument()
    expect(screen.getByText('Branche Lac 2')).toBeInTheDocument()
    expect(screen.getByText('86,400 TND')).toBeInTheDocument()
    expect(screen.getByText('reports:ownerDashboard.liveSales.items:3')).toBeInTheDocument()
  })

  it('marks rows from the newest poll as highlighted without highlighting the initial rows', () => {
    const nextLiveSales: LiveSalesReport = {
      ...initialLiveSales,
      recent_receipts: [
        {
          id: 'receipt-2',
          posted_at: '2026-07-03T13:36:11Z',
          location_id: 'loc-2',
          location_name: 'Branche Marsa',
          total: '45.000',
          currency: 'TND',
          items_count: 1,
          receipt_number: 'R-1002',
        },
        ...initialLiveSales.recent_receipts,
      ],
    }

    useLiveSalesMock.mockReturnValue({ data: initialLiveSales, isLoading: false, isError: false })
    const { rerender } = render(<LiveSalesFeed />)

    expect(screen.getByTestId('live-sale-receipt-1')).toHaveAttribute('data-highlighted', 'false')

    useLiveSalesMock.mockReturnValue({ data: nextLiveSales, isLoading: false, isError: false })
    rerender(<LiveSalesFeed />)

    expect(screen.getByTestId('live-sale-receipt-2')).toHaveAttribute('data-highlighted', 'true')
    expect(screen.getByTestId('live-sale-receipt-1')).toHaveAttribute('data-highlighted', 'false')
  })
})
