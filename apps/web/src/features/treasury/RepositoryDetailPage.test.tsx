import { describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { RepositoryDetailPage } from './RepositoryDetailPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('react-router-dom', () => ({
  useParams: () => ({ id: 'repo-1' }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('../../stores/authStore', () => {
  const authState = { user: { tenant_id: 'tenant-1' } }
  const useAuthStore = (selector: (s: unknown) => unknown) => selector(authState)
  useAuthStore.getState = () => authState
  return { useAuthStore }
})

vi.mock('../../stores/companyStore', () => {
  const companyState = {
    currentCompanyId: 'company-1',
    getCurrentCompany: () => ({ currency: 'TND', locale: 'fr_FR' }),
  }
  const useCompanyStore = (selector: (s: unknown) => unknown) => selector(companyState)
  useCompanyStore.getState = () => companyState
  return { useCompanyStore }
})

vi.mock('../finance/hooks/useAccounts', () => ({
  useAccounts: () => ({ data: [] }),
}))

const repository = {
  id: 'repo-1',
  code: 'CASH-01',
  name: 'Main Register',
  type: 'cash_register' as const,
  bank_name: null,
  account_number: null,
  iban: null,
  bic: null,
  balance: '1500.000',
  is_active: true,
  gl_account_id: null,
  gl_account: null,
}

const transaction = {
  id: 'txn-1',
  payment_number: 'PAY-001',
  partner_id: 'partner-1',
  partner_name: 'Acme',
  payment_method_name: 'Cash',
  amount: '250.000',
  currency: 'TND',
  payment_date: '2026-06-01',
  status: 'completed',
  payment_type: null,
  reference: null,
  notes: null,
  allocations: [],
  created_at: '2026-06-01T00:00:00Z',
}

// Drive the two useQuery calls deterministically by inspecting the queryKey:
// ['payment-repository', id] vs ['payment-repository-transactions', id].
vi.mock('@tanstack/react-query', () => ({
  useQuery: ({ queryKey }: { queryKey: unknown[] }) => {
    const flat = JSON.stringify(queryKey)
    if (flat.includes('payment-repository-transactions')) {
      return { data: { data: [transaction] }, isLoading: false }
    }
    return { data: { data: repository }, isLoading: false, error: null }
  },
  useMutation: () => ({ mutate: vi.fn(), isPending: false }),
  useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}))

describe('RepositoryDetailPage', () => {
  it('renders exactly one h1 (the repository name) via PageHeader', () => {
    render(<RepositoryDetailPage />)
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('Main Register')
  })

  it('renders the repository type as a StatusBadge pill', () => {
    render(<RepositoryDetailPage />)
    const typePill = screen.getByText('treasury:repositories.types.cash_register')
    expect(typePill.className).toContain('rounded-full')
  })

  it('renders the transaction status as a StatusBadge pill', () => {
    render(<RepositoryDetailPage />)
    const table = screen.getByRole('table')
    // t(key, 'completed') returns the string fallback 'completed' per the i18n mock.
    const statusPill = within(table).getByText('completed')
    expect(statusPill.className).toContain('rounded-full')
  })
})
