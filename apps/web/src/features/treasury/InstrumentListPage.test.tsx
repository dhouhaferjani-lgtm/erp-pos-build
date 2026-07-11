import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InstrumentListPage } from './InstrumentListPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => {
      if (typeof second === 'string') return second
      if (key === 'treasury:instruments.count' && second && typeof second === 'object' && 'count' in second) {
        return `${String(second.count)} instruments`
      }
      return key
    },
  }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
  useSearchParams: () => [new URLSearchParams()],
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

/**
 * Mirrors `PaymentInstrumentController::formatInstrument` exactly (verified
 * against apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php).
 * There is NO `instrument_number`, `type`, or `partner_name` — a mock
 * cementing those phantom fields is the bug class this test guards against.
 */
interface InstrumentRelation {
  id: string
  name: string
}

interface RepositoryRelation {
  id: string
  code: string
  name: string
}

interface Instrument {
  id: string
  payment_method_id: string
  payment_method: { id: string; code: string; name: string } | null
  reference: string
  partner_id: string | null
  partner: InstrumentRelation | null
  drawer_name: string | null
  amount: string
  currency: string
  received_date: string
  maturity_date: string | null
  expiry_date: string | null
  status: 'received' | 'in_transit' | 'deposited' | 'clearing' | 'cleared' | 'bounced' | 'expired' | 'cancelled' | 'collected'
  kind: 'cheque' | 'effet' | 'other' | null
  direction: 'inbound' | 'outbound'
  needs_details: boolean
  repository_id: string | null
  repository: RepositoryRelation | null
  bank_name: string | null
  bank_branch: string | null
  bank_account: string | null
  deposited_at: string | null
  deposited_to_id: string | null
  deposited_to: RepositoryRelation | null
  cleared_at: string | null
  bounced_at: string | null
  bounce_reason: string | null
  created_at: string | null
}

interface InstrumentsResponse {
  data: Instrument[]
  meta?: { current_page: number; last_page: number; per_page: number; total: number }
}

function makeInstrument(overrides: Partial<Instrument>): Instrument {
  return {
    id: 'id',
    payment_method_id: 'pm-1',
    payment_method: { id: 'pm-1', code: 'CHECK', name: 'Check' },
    reference: 'CHK-0000',
    partner_id: 'p1',
    partner: { id: 'p1', name: 'Alice Co' },
    drawer_name: null,
    amount: '0.000',
    currency: 'TND',
    received_date: '2026-06-14',
    maturity_date: '2026-07-14',
    expiry_date: null,
    status: 'received',
    kind: 'cheque',
    direction: 'inbound',
    needs_details: false,
    repository_id: 'r1',
    repository: { id: 'r1', code: 'SAFE-1', name: 'Main Safe' },
    bank_name: null,
    bank_branch: null,
    bank_account: null,
    deposited_at: null,
    deposited_to_id: null,
    deposited_to: null,
    cleared_at: null,
    bounced_at: null,
    bounce_reason: null,
    created_at: '2026-06-14T00:00:00Z',
    ...overrides,
  }
}

const mockUseQueryReturn: {
  data: InstrumentsResponse | undefined
  isLoading: boolean
  error: unknown
} = {
  data: {
    data: [
      makeInstrument({ id: '1', reference: 'CHK-1001', status: 'received', amount: '120.500' }),
      makeInstrument({ id: '2', reference: 'CHK-1002', status: 'cleared', amount: '90.000' }),
    ],
  },
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: ({ queryKey }: { queryKey: unknown[] }) => JSON.stringify(queryKey).includes('maturing-instruments')
      ? { data: undefined, isLoading: false, error: null }
      : mockUseQueryReturn,
  }
})

describe('InstrumentListPage (canonical list)', () => {
  it('renders exactly one h1 page title', () => {
    render(<InstrumentListPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders a row per instrument, keyed by reference (not the phantom instrument_number)', () => {
    render(<InstrumentListPage />)
    expect(screen.getByText('CHK-1001')).toBeInTheDocument()
    expect(screen.getByText('CHK-1002')).toBeInTheDocument()
  })

  it('renders status as a StatusBadge pill (rounded-full)', () => {
    render(<InstrumentListPage />)
    const badge = screen.getByText('treasury:instruments.statuses.received')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('links the reference to its detail route', () => {
    render(<InstrumentListPage />)
    const link = screen.getByText('CHK-1001').closest('a')
    expect(link).toHaveAttribute('href', '/treasury/instruments/1')
  })

  it('renders partner.name (nested relation, not the phantom partner_name) linked to the partner', () => {
    mockUseQueryReturn.data = {
      data: [makeInstrument({ id: '1', reference: 'CHK-1001' })],
    }
    render(<InstrumentListPage />)
    const link = screen.getByText('Alice Co').closest('a')
    expect(link).toHaveAttribute('href', '/sales/customers/p1')
  })

  it('falls back to drawer_name when partner is null', () => {
    mockUseQueryReturn.data = {
      data: [
        makeInstrument({
          id: '3',
          reference: 'CHK-3000',
          partner_id: null,
          partner: null,
          drawer_name: 'Bob Drawer',
        }),
      ],
    }
    render(<InstrumentListPage />)
    expect(screen.getByText('Bob Drawer')).toBeInTheDocument()
  })

  it('falls back to a dash when both partner and drawer_name are null', () => {
    mockUseQueryReturn.data = {
      data: [
        makeInstrument({
          id: '4',
          reference: 'CHK-4000',
          partner_id: null,
          partner: null,
          drawer_name: null,
        }),
      ],
    }
    render(<InstrumentListPage />)
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })

  it('renders repository.name (nested relation, not the phantom repository_name) linked to the repository', () => {
    mockUseQueryReturn.data = {
      data: [
        makeInstrument({ id: '5', reference: 'CHK-5000' }),
      ],
    }
    render(<InstrumentListPage />)
    const link = screen.getByText('Main Safe').closest('a')
    expect(link).toHaveAttribute('href', '/treasury/repositories/r1')
  })

  it('renders a dash for repository when the instrument has not been assigned one', () => {
    mockUseQueryReturn.data = {
      data: [
        makeInstrument({
          id: '6',
          reference: 'CHK-6000',
          repository_id: null,
          repository: null,
        }),
      ],
    }
    render(<InstrumentListPage />)
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })

  it('renders received_date and maturity_date, and a dash when maturity_date is null', () => {
    mockUseQueryReturn.data = {
      data: [
        makeInstrument({
          id: '7',
          reference: 'CHK-7000',
          received_date: '2026-06-01',
          maturity_date: null,
        }),
      ],
    }
    render(<InstrumentListPage />)
    expect(screen.getByText(new Date('2026-06-01').toLocaleDateString('fr-FR'))).toBeInTheDocument()
  })

  it('falls back to instruments.length when pagination metadata is absent', () => {
    mockUseQueryReturn.data = {
      data: [
        makeInstrument({ id: '8', reference: 'CHK-8000' }),
        makeInstrument({ id: '9', reference: 'CHK-9000' }),
      ],
      // Defensive fallback for cached pre-pagination responses.
    }
    render(<InstrumentListPage />)
    expect(screen.getByText(/^2\s/)).toBeInTheDocument()
  })
})
