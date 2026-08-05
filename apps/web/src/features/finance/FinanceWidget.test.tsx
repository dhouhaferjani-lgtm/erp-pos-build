import { render, screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { useCompanyStore } from '@/stores/companyStore'
import { FinanceWidget } from './components/FinanceWidget'

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

// NO `vi.mock('@/lib/format', …)` HERE — ON PURPOSE.
//
// This file used to stub the whole formatter module with a hand-rolled
// en-US/EUR/2dp formatter and assert its output (`'50,000.00'`). Because the
// module mock intercepts `@/lib/format` for the entire graph it also neutered
// `useCurrency` -> `formatAmount` -> `formatCurrency`, so the suite rendered the
// stub rather than the product: it passed identically before and after the W-6
// D6 fix, and would stay green if D6 were reverted — while leaving the DEFECT's
// exact render (`50,000.00 EUR` on a Tunisian company) in the repo as an
// EXPECTED value. That is the "unit tests lock the wrong render in" pattern the
// F-7 ticket calls out.
//
// The real formatter now runs, against a seeded TND company, so these assertions
// describe the product. See `components/FinanceWidget.currency.test.tsx` for the
// per-tile currency/scale pins.

/** fr-TN groups with U+202F (narrow no-break space) — spelled as an escape so it
 * survives copy/paste and is visible in a diff. */
const NNBSP = '\u202f'

describe('FinanceWidget', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
      },
    })
    vi.clearAllMocks()
    seedAuth()
    // seedAuth leaves `companies: []`, i.e. no selected company — which would
    // send the formatter down its EUR fallback. Give it the Tunisian company the
    // D6 ticket is written against.
    useCompanyStore.setState({
      currentCompanyId: 'test-company-id',
      companies: [
        {
          id: 'test-company-id',
          name: 'PharmaBio Tunisie SARL',
          legalName: 'PharmaBio Tunisie SARL',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'fr_TN',
          timezone: 'Africa/Tunis',
        },
      ],
    })
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders widget title', () => {
    mockApiGet.mockResolvedValue({
      total_assets: '0.00',
      total_liabilities: '0.00',
      total_equity: '0.00',
      net_income_mtd: '0.00',
      net_income_ytd: '0.00',
      accounts_receivable: '0.00',
      accounts_payable: '0.00',
    })

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    expect(screen.getByText(/Finance Overview/i)).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockApiGet.mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays total assets', async () => {
    const mockData = {
      total_assets: '50000.00',
      total_liabilities: '20000.00',
      total_equity: '30000.00',
      net_income_mtd: '5000.00',
      net_income_ytd: '25000.00',
      accounts_receivable: '10000.00',
      accounts_payable: '8000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    await waitFor(() => {
      expect(screen.getByText(/Total Assets/i)).toBeInTheDocument()
      const matches = screen.getAllByText((_content, element) => {
        return element?.textContent?.includes(`50${NNBSP}000,000 TND`) ?? false
      })
      expect(matches.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays total liabilities', async () => {
    const mockData = {
      total_assets: '50000.00',
      total_liabilities: '20000.00',
      total_equity: '30000.00',
      net_income_mtd: '5000.00',
      net_income_ytd: '25000.00',
      accounts_receivable: '10000.00',
      accounts_payable: '8000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    await waitFor(() => {
      expect(screen.getByText(/Total Liabilities/i)).toBeInTheDocument()
      const matches = screen.getAllByText((_content, element) => {
        return element?.textContent?.includes(`20${NNBSP}000,000 TND`) ?? false
      })
      expect(matches.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays net income MTD', async () => {
    const mockData = {
      total_assets: '50000.00',
      total_liabilities: '20000.00',
      total_equity: '30000.00',
      net_income_mtd: '5000.00',
      net_income_ytd: '25000.00',
      accounts_receivable: '10000.00',
      accounts_payable: '8000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    await waitFor(() => {
      expect(screen.getByText(/Net Income.*MTD/i)).toBeInTheDocument()
      const matches = screen.getAllByText((_content, element) => {
        return element?.textContent?.includes(`5${NNBSP}000,000 TND`) ?? false
      })
      expect(matches.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays accounts receivable', async () => {
    const mockData = {
      total_assets: '50000.00',
      total_liabilities: '20000.00',
      total_equity: '30000.00',
      net_income_mtd: '5000.00',
      net_income_ytd: '25000.00',
      accounts_receivable: '10000.00',
      accounts_payable: '8000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    await waitFor(() => {
      expect(screen.getByText(/Accounts Receivable/i)).toBeInTheDocument()
      const matches = screen.getAllByText((_content, element) => {
        return element?.textContent?.includes(`10${NNBSP}000,000 TND`) ?? false
      })
      expect(matches.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('displays accounts payable', async () => {
    const mockData = {
      total_assets: '50000.00',
      total_liabilities: '20000.00',
      total_equity: '30000.00',
      net_income_mtd: '5000.00',
      net_income_ytd: '25000.00',
      accounts_receivable: '10000.00',
      accounts_payable: '8000.00',
    }

    mockApiGet.mockResolvedValue(mockData)

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    await waitFor(() => {
      expect(screen.getByText(/Accounts Payable/i)).toBeInTheDocument()
      const matches = screen.getAllByText((_content, element) => {
        return element?.textContent?.includes(`8${NNBSP}000,000 TND`) ?? false
      })
      expect(matches.length).toBeGreaterThanOrEqual(1)
    })
  })

  it('has link to full finance reports', async () => {
    mockApiGet.mockResolvedValue({
      total_assets: '0.00',
      total_liabilities: '0.00',
      total_equity: '0.00',
      net_income_mtd: '0.00',
      net_income_ytd: '0.00',
      accounts_receivable: '0.00',
      accounts_payable: '0.00',
    })

    render(
      <MemoryRouter>
        <QueryClientProvider client={queryClient}>
          <FinanceWidget />
        </QueryClientProvider>
      </MemoryRouter>
    )

    await waitFor(() => {
      expect(screen.getByText(/View Reports/i)).toBeInTheDocument()
    })
  })
})
