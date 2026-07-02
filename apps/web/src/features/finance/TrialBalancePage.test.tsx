import { screen, waitFor } from '@testing-library/react'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { useCompanyStore } from '@/stores/companyStore'
import { TrialBalancePage } from './pages/TrialBalancePage'
import {
  makeTrialBalanceLine,
  makeTrialBalanceReport,
} from './__fixtures__/trialBalance'

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

describe('TrialBalancePage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
    useCompanyStore.setState({
      currentCompanyId: 'test-company-id',
      companies: [
        {
          id: 'test-company-id',
          name: 'PharmaBio Tunisie',
          legalName: 'PharmaBio Tunisie',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'fr_TN',
          timezone: 'Africa/Tunis',
        },
      ],
      isLoading: false,
    })
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders page title', () => {
    mockApiGet.mockResolvedValue(makeTrialBalanceReport({ lines: [] }))

    renderWithProviders(<TrialBalancePage />)

    expect(screen.getByText('Trial Balance')).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockApiGet.mockImplementation(
      () => new Promise(() => {}) // Never resolves
    )

    renderWithProviders(<TrialBalancePage />)

    expect(screen.getByText(/loading/i)).toBeInTheDocument()
  })

  it('displays trial balance data', async () => {
    mockApiGet.mockResolvedValue(
      makeTrialBalanceReport({
        lines: [
          makeTrialBalanceLine({
            account_code: '1000',
            account_name: 'Cash',
            account_type: 'asset',
            debit: '5000.00',
            credit: '0.00',
          }),
          makeTrialBalanceLine({
            account_code: '3000',
            account_name: 'Capital',
            account_type: 'equity',
            debit: '0.00',
            credit: '5000.00',
          }),
        ],
        total_debit: '5000.00',
        total_credit: '5000.00',
      }),
    )

    renderWithProviders(<TrialBalancePage />)

    await waitFor(() => {
      expect(screen.getByText('1000')).toBeInTheDocument()
      expect(screen.getByText('Cash')).toBeInTheDocument()
      expect(screen.getByText('3000')).toBeInTheDocument()
      expect(screen.getByText('Capital')).toBeInTheDocument()
    })
  })

  it('displays totals row', async () => {
    mockApiGet.mockResolvedValue(
      makeTrialBalanceReport({
        lines: [
          makeTrialBalanceLine({
            account_code: '1000',
            account_name: 'Cash',
            account_type: 'asset',
            debit: '5000.00',
            credit: '0.00',
          }),
          makeTrialBalanceLine({
            account_code: '3000',
            account_name: 'Capital',
            account_type: 'equity',
            debit: '0.00',
            credit: '5000.00',
          }),
        ],
        total_debit: '5000.00',
        total_credit: '5000.00',
      }),
    )

    renderWithProviders(<TrialBalancePage />)

    await waitFor(() => {
      expect(screen.getByText('Total')).toBeInTheDocument()
      // Total debits and credits should both use the tenant TND formatter.
      const amounts = screen.getAllByText('5 000,000 TND')
      expect(amounts.length).toBeGreaterThanOrEqual(2)
    })
  })

  it('formats trial balance amounts with the tenant TND formatter', async () => {
    mockApiGet.mockResolvedValue(
      makeTrialBalanceReport({
        lines: [
          makeTrialBalanceLine({
            account_code: '1000',
            account_name: 'Cash',
            account_type: 'asset',
            debit: '6607.60',
            credit: '0.00',
          }),
        ],
        total_debit: '6607.60',
        total_credit: '0.00',
      }),
    )

    renderWithProviders(<TrialBalancePage />)

    await waitFor(() => {
      expect(screen.getAllByText('6 607,600 TND').length).toBeGreaterThanOrEqual(2)
    })
    expect(screen.queryByText('6,607.60')).not.toBeInTheDocument()
  })

  it('has export button', () => {
    mockApiGet.mockResolvedValue(makeTrialBalanceReport({ lines: [] }))

    renderWithProviders(<TrialBalancePage />)

    expect(screen.getByText(/export/i)).toBeInTheDocument()
  })

  it('has date filter', () => {
    mockApiGet.mockResolvedValue(makeTrialBalanceReport({ lines: [] }))

    renderWithProviders(<TrialBalancePage />)

    expect(screen.getByLabelText(/as of date/i)).toBeInTheDocument()
  })
})
