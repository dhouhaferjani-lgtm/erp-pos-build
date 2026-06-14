import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { GeneralLedgerPage } from './pages/GeneralLedgerPage'
import {
  makeLedgerLine,
  makeLedgerReport,
} from './__fixtures__/generalLedger'

const { mockApiGet } = vi.hoisted(() => ({
  mockApiGet: vi.fn(),
}))

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
  apiPost: vi.fn(),
  apiPatch: vi.fn(),
}))

// Accounts feed the account-filter dropdown only. getAccounts returns a
// raw Account[] (no wrapper), so a plain array mock is correct here and
// intentionally stays outside the ledger fixture factory scope.
const mockAccounts = [
  {
    id: '1',
    code: '1000',
    name: 'Cash',
    type: 'asset',
  },
  {
    id: '2',
    code: '4000',
    name: 'Revenue',
    type: 'revenue',
  },
]

const mockLedgerReport = makeLedgerReport({
  lines: [
    makeLedgerLine({
      id: '1',
      date: '2025-01-15',
      entry_number: 'JE-001',
      description: 'Cash sale',
      account_code: '1000',
      account_name: 'Cash',
      debit: '1000.00',
      credit: '0.00',
      balance: '1000.00',
      source_type: 'invoice',
      source_id: 'inv-1',
    }),
    makeLedgerLine({
      id: '2',
      date: '2025-01-15',
      entry_number: 'JE-001',
      description: 'Cash sale',
      account_code: '4000',
      account_name: 'Revenue',
      debit: '0.00',
      credit: '1000.00',
      balance: '-1000.00',
      source_type: 'invoice',
      source_id: 'inv-1',
    }),
  ],
  total_debits: '1000.00',
  total_credits: '1000.00',
  closing_balance: '0.00',
})

describe('GeneralLedgerPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders the page title', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/accounts')) {
        return Promise.resolve(mockAccounts)
      }
      if (url.includes('/ledger')) {
        return Promise.resolve(mockLedgerReport)
      }
      return Promise.resolve([])
    })

    renderWithProviders(<GeneralLedgerPage />)

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: /general ledger/i })).toBeInTheDocument()
    })
  })

  it('displays loading state initially', () => {
    mockApiGet.mockImplementation(() => new Promise(() => {}))

    renderWithProviders(<GeneralLedgerPage />)

    // LedgerTable now renders the DataTable animated skeleton while loading.
    expect(document.querySelector('.animate-pulse')).toBeInTheDocument()
  })

  it('displays ledger entries', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/accounts')) {
        return Promise.resolve(mockAccounts)
      }
      if (url.includes('/ledger')) {
        return Promise.resolve(mockLedgerReport)
      }
      return Promise.resolve([])
    })

    renderWithProviders(<GeneralLedgerPage />)

    await waitFor(() => {
      const entryNumbers = screen.getAllByText('JE-001')
      expect(entryNumbers.length).toBeGreaterThan(0)
      const descriptions = screen.getAllByText('Cash sale')
      expect(descriptions.length).toBeGreaterThan(0)
      expect(screen.getByText('Cash')).toBeInTheDocument()
    })
  })

  it('has account filter dropdown', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/accounts')) {
        return Promise.resolve(mockAccounts)
      }
      if (url.includes('/ledger')) {
        return Promise.resolve(mockLedgerReport)
      }
      return Promise.resolve([])
    })

    renderWithProviders(<GeneralLedgerPage />)

    await waitFor(() => {
      expect(screen.getByLabelText(/account/i)).toBeInTheDocument()
    })
  })

  it('has date range filters', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/accounts')) {
        return Promise.resolve(mockAccounts)
      }
      if (url.includes('/ledger')) {
        return Promise.resolve(mockLedgerReport)
      }
      return Promise.resolve([])
    })

    renderWithProviders(<GeneralLedgerPage />)

    await waitFor(() => {
      expect(screen.getByLabelText(/from date/i)).toBeInTheDocument()
      expect(screen.getByLabelText(/to date/i)).toBeInTheDocument()
    })
  })

  it('applies filters when changed', async () => {
    const user = userEvent.setup()
    mockApiGet.mockImplementation((url: string) => {
      if (url.includes('/accounts')) {
        return Promise.resolve(mockAccounts)
      }
      if (url.includes('/ledger')) {
        return Promise.resolve(mockLedgerReport)
      }
      return Promise.resolve([])
    })

    renderWithProviders(<GeneralLedgerPage />)

    await waitFor(() => {
      expect(screen.getByLabelText(/account/i)).toBeInTheDocument()
    })

    const accountSelect = screen.getByLabelText(/account/i)
    // Filters render eagerly in the canonical always-visible filter slot, so
    // wait for the account options to load before selecting.
    await waitFor(() => {
      expect(screen.getByRole('option', { name: /1000 - Cash/ })).toBeInTheDocument()
    })
    await user.selectOptions(accountSelect, '1')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(expect.stringContaining('account_id=1'))
    })
  })
})
