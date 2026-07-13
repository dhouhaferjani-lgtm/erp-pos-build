import { describe, it, expect, vi, afterEach } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'
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
  currency: 'USD',
  is_active: true,
  gl_account_id: null,
  gl_account: null,
}

let canAdjust = true
let canTransfer = true
let repositoryIsActive = true
let repositoryType: 'cash_register' | 'virtual' = 'cash_register'

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: (permission: string) =>
      (permission === 'treasury.adjust' && canAdjust)
      || (permission === 'treasury.transfer' && canTransfer),
  }),
}))

vi.mock('./components/AdjustBalanceDialog', () => ({
  AdjustBalanceDialog: ({
    isOpen,
    repositoryCurrency,
    onSuccess,
  }: {
    isOpen: boolean
    repositoryCurrency: string
    onSuccess?: () => void
  }) => isOpen ? (
    <div role="dialog">
      <span>{repositoryCurrency}</span>
      <button onClick={onSuccess}>complete adjustment</button>
    </div>
  ) : null,
}))

vi.mock('./components/TransferCashModal', () => ({
  TransferCashModal: ({
    isOpen,
    initialFromRepositoryId,
  }: {
    isOpen: boolean
    initialFromRepositoryId?: string
  }) => isOpen ? (
    <div role="dialog" aria-label="transfer cash">
      {initialFromRepositoryId}
    </div>
  ) : null,
}))

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

// A refund payment stores a NEGATIVE amount (PaymentRefundService) — unlike
// RepositoryMovement.amount, which is unsigned with a separate `direction`.
const refundTransaction = {
  ...transaction,
  id: 'txn-2',
  payment_number: 'PAY-002',
  amount: '-50000.000',
}

// Mutable so a single test can inject an extra (refund) row without
// affecting the other tests in this file, which assert against the single
// fixed `transaction` shape.
let mockTransactions: unknown[] = [transaction]

// Drive the two useQuery calls deterministically by inspecting the queryKey:
// ['payment-repository', id] vs ['payment-repository-transactions', id].
vi.mock('@tanstack/react-query', () => ({
  useQuery: ({ queryKey }: { queryKey: unknown[] }) => {
    const flat = JSON.stringify(queryKey)
    if (flat.includes('payment-repository-transactions')) {
      return { data: { data: mockTransactions }, isLoading: false }
    }
    return {
      data: {
        data: {
          ...repository,
          is_active: repositoryIsActive,
          type: repositoryType,
        },
      },
      isLoading: false,
      error: null,
    }
  },
  useMutation: () => ({ mutate: vi.fn(), isPending: false }),
  useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}))

describe('RepositoryDetailPage', () => {
  afterEach(() => {
    mockTransactions = [transaction]
    canAdjust = true
    canTransfer = true
    repositoryIsActive = true
    repositoryType = 'cash_register'
  })

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

  it('renders a refund transaction with a single leading sign (no "+-" double sign)', () => {
    mockTransactions = [transaction, refundTransaction]

    render(<RepositoryDetailPage />)

    const table = screen.getByRole('table')
    const refundRow = within(table).getByText('PAY-002').closest('tr')
    expect(refundRow).not.toBeNull()

    const amountCell = within(refundRow as HTMLElement).getAllByRole('cell').at(-1)
    expect(amountCell?.textContent).not.toMatch(/\+-/)
    expect(amountCell?.textContent?.trim().startsWith('-')).toBe(true)
  })

  it('shows the adjustment action only with treasury.adjust permission', () => {
    const { rerender } = render(<RepositoryDetailPage />)
    expect(screen.getByRole('button', { name: 'treasury:repositories.adjustBalance.action' })).toBeInTheDocument()

    canAdjust = false
    rerender(<RepositoryDetailPage />)
    expect(screen.queryByRole('button', { name: 'treasury:repositories.adjustBalance.action' })).not.toBeInTheDocument()
  })

  it('opens the adjustment dialog with repository currency and closes it after success', () => {
    render(<RepositoryDetailPage />)

    fireEvent.click(screen.getByRole('button', { name: 'treasury:repositories.adjustBalance.action' }))
    expect(screen.getByRole('dialog')).toHaveTextContent('USD')

    fireEvent.click(screen.getByRole('button', { name: 'complete adjustment' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('shows the transfer action only with treasury.transfer permission', () => {
    const { rerender } = render(<RepositoryDetailPage />)
    expect(screen.getByRole('button', { name: 'treasury:repositories.transfer.action' })).toBeInTheDocument()

    canTransfer = false
    rerender(<RepositoryDetailPage />)
    expect(screen.queryByRole('button', { name: 'treasury:repositories.transfer.action' })).not.toBeInTheDocument()
  })

  it.each([
    ['inactive', { isActive: false, type: 'cash_register' as const }],
    ['virtual', { isActive: true, type: 'virtual' as const }],
  ])('hides the transfer action when the repository is %s', (_label, repositoryState) => {
    repositoryIsActive = repositoryState.isActive
    repositoryType = repositoryState.type

    render(<RepositoryDetailPage />)

    expect(screen.queryByRole('button', { name: 'treasury:repositories.transfer.action' })).not.toBeInTheDocument()
  })

  it('shows the transfer action for an active physical repository with permission', () => {
    render(<RepositoryDetailPage />)

    expect(screen.getByRole('button', { name: 'treasury:repositories.transfer.action' })).toBeInTheDocument()
  })

  it('opens the transfer modal with the current repository preselected', () => {
    render(<RepositoryDetailPage />)

    fireEvent.click(screen.getByRole('button', { name: 'treasury:repositories.transfer.action' }))

    expect(screen.getByRole('dialog', { name: 'transfer cash' })).toHaveTextContent('repo-1')
  })
})
