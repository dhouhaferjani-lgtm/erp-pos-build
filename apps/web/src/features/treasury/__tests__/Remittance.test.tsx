import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { BordereauPrintView } from '../components/BordereauPrintView'
import { RemittanceCreatePage } from '../RemittanceCreatePage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({ api: { get: mockApiGet, post: mockApiPost } }))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return { ...actual, Link: ({ children, to }: { children: ReactNode; to: string }) => <a href={to}>{children}</a>, useNavigate: () => mockNavigate, useSearchParams: () => [new URLSearchParams()] }
})

function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{children}</QueryClientProvider>
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuthStore.setState({
    user: { id: 'user-1', name: 'User', email: 'u@example.test', tenant_id: 'tenant-1', roles: [], permissions: ['instruments.remit'], email_verified_at: null },
    token: 'token', isAuthenticated: true, isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
  mockApiGet.mockImplementation((url: string) => {
    if (url === '/payment-repositories') return Promise.resolve({ data: { data: [{ id: 'bank-1', code: 'BANK', name: 'Main Bank', type: 'bank_account', account_number: 'RIB-123', iban: 'TN59', bank_name: 'BIAT' }] } })
    if (url.startsWith('/payment-instruments?')) return Promise.resolve({ data: { data: [{ id: 'instrument-1', reference: 'CHK-1', amount: '125.500', currency: 'TND', maturity_date: '2099-01-01', drawer_name: 'Acme', bank_name: 'UBCI' }], meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 } } })
    return Promise.resolve({ data: { data: [] } })
  })
  mockApiPost.mockImplementation((url: string) => {
    if (url === '/instrument-remittances') return Promise.resolve({ data: { data: { id: 'slip-1' } } })
    return Promise.resolve({ data: { data: {} } })
  })
})

describe('Remittance', () => {
  it('creates a draft, adds selected instruments, then remits it', async () => {
    render(<RemittanceCreatePage />, { wrapper })

    await screen.findByRole('option', { name: 'Main Bank' })
    await userEvent.selectOptions(screen.getByLabelText('treasury:remittances.bankRepository'), 'bank-1')
    await userEvent.selectOptions(screen.getByLabelText('treasury:remittances.kind'), 'cheque')
    await userEvent.click(await screen.findByRole('checkbox', { name: /CHK-1/ }))
    expect(screen.getByText('treasury:remittances.earlyWarning')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'treasury:remittances.createAndRemit' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenNthCalledWith(1, '/instrument-remittances', {
        bank_repository_id: 'bank-1', instrument_kind: 'cheque', remittance_type: 'collection',
      })
      expect(mockApiPost).toHaveBeenNthCalledWith(2, '/instrument-remittances/slip-1/lines', { instrument_id: 'instrument-1' })
      expect(mockApiPost).toHaveBeenNthCalledWith(3, '/instrument-remittances/slip-1/remit')
    })
    expect(mockNavigate).toHaveBeenCalledWith('/treasury/remittances/slip-1')
  })

  it('prints one bordereau row with count and exact decimal total', () => {
    render(<BordereauPrintView slip={{
      id: 'slip-1', number: 'REM-0001', remittance_type: 'collection', instrument_kind: 'effet', status: 'remitted', remitted_at: '2026-07-11T10:00:00Z', created_at: '2026-07-11T09:00:00Z', journal_entry_id: null,
      bank_repository_id: 'bank-1', bank_repository: { id: 'bank-1', code: 'BANK', name: 'Main Bank', bank_name: 'BIAT', account_number: 'RIB-123', iban: 'TN59' },
      lines: [{ id: 'line-1', remittance_id: 'slip-1', instrument_id: 'instrument-1', amount: '125.500', line_status: 'pending', cleared_at: null, bounced_at: null, instrument: { id: 'instrument-1', reference: 'EFF-1', amount: '125.500', currency: 'TND', status: 'deposited', drawer_name: 'Acme', bank_name: 'UBCI', maturity_date: '2026-08-01' } }],
    }} depositor="Treasury User" />)

    expect(screen.getByText('EFF-1')).toBeInTheDocument()
    expect(screen.getByText('1')).toBeInTheDocument()
    expect(screen.getAllByText(/125[.,]500/).length).toBeGreaterThan(0)
    expect(screen.getByText('RIB-123')).toBeInTheDocument()
  })
})
