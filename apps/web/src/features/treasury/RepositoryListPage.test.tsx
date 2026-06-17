import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { RepositoryListPage } from './RepositoryListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('react-router-dom', () => ({
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

// Repositories page can open an Add modal; grant the manage permission so the
// Add button renders, and stub the modal so it never actually mounts.
vi.mock('../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}))
vi.mock('../../components/organisms', () => ({
  AddRepositoryModal: () => null,
}))

interface Repository {
  id: string
  code: string
  name: string
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual'
  bank_name: string | null
  account_number: string | null
  iban: string | null
  bic: string | null
  balance: string
  is_active: boolean
}

interface RepositoriesResponse {
  data: Repository[]
}

function makeRepo(overrides: Partial<Repository>): Repository {
  return {
    id: 'id',
    code: 'CODE',
    name: 'Repo',
    type: 'cash_register',
    bank_name: null,
    account_number: null,
    iban: null,
    bic: null,
    balance: '0',
    is_active: true,
    ...overrides,
  }
}

const mockUseQueryReturn: {
  data: RepositoriesResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makeRepo({ id: '1', code: 'CASH01', name: 'Front Register', type: 'cash_register', balance: '120.50', is_active: true }),
      makeRepo({ id: '2', code: 'BANK01', name: 'Main Bank', type: 'bank_account', balance: '-30.00', is_active: false }),
    ],
  },
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => mockUseQueryReturn,
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

describe('RepositoryListPage (canonical list)', () => {
  it('renders exactly one h1 page title', () => {
    render(<RepositoryListPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders a row per repository', () => {
    render(<RepositoryListPage />)
    expect(screen.getByText('Front Register')).toBeInTheDocument()
    expect(screen.getByText('Main Bank')).toBeInTheDocument()
  })

  it('renders the active/inactive status as a StatusBadge pill (rounded-full)', () => {
    render(<RepositoryListPage />)
    const badge = screen.getByText('status.active')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('opens the Add modal control as a button', () => {
    render(<RepositoryListPage />)
    // Two add controls exist only when empty; with rows present the header
    // Add button is the single control.
    expect(
      screen.getByRole('button', { name: /treasury:repositories\.add/ }),
    ).toBeInTheDocument()
  })

  it('links the repository name to its detail route', () => {
    render(<RepositoryListPage />)
    const link = screen.getByText('Front Register').closest('a')
    expect(link).toHaveAttribute('href', '/treasury/repositories/1')
  })
})
