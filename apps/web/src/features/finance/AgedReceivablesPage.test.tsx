import { screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderWithProviders } from '@/test/renderWithProviders'
import { AgedReceivablesPage } from './pages/AgedReceivablesPage'

const { mockApiGet } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: () => true,
  }),
}))

describe('AgedReceivablesPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders page title', () => {
    mockApiGet.mockResolvedValue([])

    renderWithProviders(<AgedReceivablesPage />)

    expect(screen.getByText(/Aged Receivables/i)).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockApiGet.mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    renderWithProviders(<AgedReceivablesPage />)

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays customer receivables with aging buckets', async () => {
    const mockData = [
      {
        customer_id: '1',
        customer_name: 'ACME Corp',
        current: '1000.00',
        days_30: '500.00',
        days_60: '200.00',
        days_90: '100.00',
        over_90: '50.00',
        total: '1850.00',
      },
    ]

    mockApiGet.mockResolvedValue(mockData)

    renderWithProviders(<AgedReceivablesPage />)

    await waitFor(() => {
      expect(screen.getByText('ACME Corp')).toBeInTheDocument()
      const amounts1000 = screen.getAllByText('1,000.00')
      expect(amounts1000.length).toBeGreaterThanOrEqual(1)
      const amounts500 = screen.getAllByText('500.00')
      expect(amounts500.length).toBeGreaterThanOrEqual(1)
      const amounts200 = screen.getAllByText('200.00')
      expect(amounts200.length).toBeGreaterThanOrEqual(1)
      const amounts100 = screen.getAllByText('100.00')
      expect(amounts100.length).toBeGreaterThanOrEqual(1)
      const amounts50 = screen.getAllByText('50.00')
      expect(amounts50.length).toBeGreaterThanOrEqual(1)
      const totals = screen.getAllByText('1,850.00')
      expect(totals.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays column headers for aging buckets', async () => {
    mockApiGet.mockResolvedValue([])

    renderWithProviders(<AgedReceivablesPage />)

    await waitFor(() => {
      expect(screen.getByText('Current')).toBeInTheDocument()
    })
    expect(screen.getByText('1-30 Days')).toBeInTheDocument()
    expect(screen.getByText('31-60 Days')).toBeInTheDocument()
    expect(screen.getByText('61-90 Days')).toBeInTheDocument()
    expect(screen.getByText('Over 90 Days')).toBeInTheDocument()
  })

  it('displays totals row', async () => {
    const mockData = [
      {
        customer_id: '1',
        customer_name: 'ACME Corp',
        current: '1000.00',
        days_30: '500.00',
        days_60: '200.00',
        days_90: '100.00',
        over_90: '50.00',
        total: '1850.00',
      },
      {
        customer_id: '2',
        customer_name: 'Widget Inc',
        current: '500.00',
        days_30: '0.00',
        days_60: '0.00',
        days_90: '0.00',
        over_90: '0.00',
        total: '500.00',
      },
    ]

    mockApiGet.mockResolvedValue(mockData)

    renderWithProviders(<AgedReceivablesPage />)

    await waitFor(() => {
      const totals = screen.getAllByText('Total')
      expect(totals.length).toBeGreaterThanOrEqual(1)
      const totalAmount = screen.getAllByText('2,350.00')
      expect(totalAmount.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('has export button', () => {
    mockApiGet.mockResolvedValue([])

    renderWithProviders(<AgedReceivablesPage />)

    expect(screen.getByText(/export/i)).toBeInTheDocument()
  })

  it('has date filter', () => {
    mockApiGet.mockResolvedValue([])

    renderWithProviders(<AgedReceivablesPage />)

    expect(screen.getByLabelText(/as of date/i)).toBeInTheDocument()
  })
})
