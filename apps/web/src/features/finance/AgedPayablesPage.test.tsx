import { screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderWithProviders } from '@/test/renderWithProviders'
import { AgedPayablesPage } from './pages/AgedPayablesPage'

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

describe('AgedPayablesPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders page title', () => {
    mockApiGet.mockResolvedValue([])

    renderWithProviders(<AgedPayablesPage />)

    expect(screen.getByText(/Aged Payables/i)).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockApiGet.mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    renderWithProviders(<AgedPayablesPage />)

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays vendor payables with aging buckets', async () => {
    const mockData = [
      {
        vendor_id: '1',
        vendor_name: 'Supplier Co',
        current: '2000.00',
        days_30: '1000.00',
        days_60: '500.00',
        days_90: '200.00',
        over_90: '100.00',
        total: '3800.00',
      },
    ]

    mockApiGet.mockResolvedValue(mockData)

    renderWithProviders(<AgedPayablesPage />)

    await waitFor(() => {
      expect(screen.getByText('Supplier Co')).toBeInTheDocument()
      const totals = screen.getAllByText('3,800.00')
      expect(totals.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays totals row', async () => {
    const mockData = [
      {
        vendor_id: '1',
        vendor_name: 'Supplier Co',
        current: '2000.00',
        days_30: '0.00',
        days_60: '0.00',
        days_90: '0.00',
        over_90: '0.00',
        total: '2000.00',
      },
    ]

    mockApiGet.mockResolvedValue(mockData)

    renderWithProviders(<AgedPayablesPage />)

    await waitFor(() => {
      const totals = screen.getAllByText('Total')
      expect(totals.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('has export button', () => {
    mockApiGet.mockResolvedValue([])

    renderWithProviders(<AgedPayablesPage />)

    expect(screen.getByText(/export/i)).toBeInTheDocument()
  })

  it('has date filter', () => {
    mockApiGet.mockResolvedValue([])

    renderWithProviders(<AgedPayablesPage />)

    expect(screen.getByLabelText(/as of date/i)).toBeInTheDocument()
  })
})
