import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InstrumentListPage } from './InstrumentListPage'

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

interface Instrument {
  id: string
  instrument_number: string
  type: 'check' | 'promissory_note' | 'voucher'
  amount: number
  issue_date: string
  maturity_date: string | null
  partner_id: string
  partner_name: string
  status: 'received' | 'deposited' | 'cleared' | 'bounced' | 'cancelled'
  repository_id: string
  repository_name: string
  created_at: string
}

interface InstrumentsResponse {
  data: Instrument[]
  meta?: { total: number }
}

function makeInstrument(overrides: Partial<Instrument>): Instrument {
  return {
    id: 'id',
    instrument_number: 'CHK-0000',
    type: 'check',
    amount: 0,
    issue_date: '2026-06-14',
    maturity_date: '2026-07-14',
    partner_id: 'p1',
    partner_name: 'Alice Co',
    status: 'received',
    repository_id: 'r1',
    repository_name: 'Main Safe',
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
      makeInstrument({ id: '1', instrument_number: 'CHK-1001', status: 'received', amount: 120.5 }),
      makeInstrument({ id: '2', instrument_number: 'CHK-1002', status: 'cleared', amount: 90 }),
    ],
    meta: { total: 2 },
  },
  isLoading: false,
  error: null,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return { ...actual, useQuery: () => mockUseQueryReturn }
})

describe('InstrumentListPage (canonical list)', () => {
  it('renders exactly one h1 page title', () => {
    render(<InstrumentListPage />)
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders a row per instrument', () => {
    render(<InstrumentListPage />)
    expect(screen.getByText('CHK-1001')).toBeInTheDocument()
    expect(screen.getByText('CHK-1002')).toBeInTheDocument()
  })

  it('renders status as a StatusBadge pill (rounded-full)', () => {
    render(<InstrumentListPage />)
    // i18n mock returns the string default (2nd arg), so the label is the raw status.
    const badge = screen.getByText('received')
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('links the instrument number to its detail route', () => {
    render(<InstrumentListPage />)
    const link = screen.getByText('CHK-1001').closest('a')
    expect(link).toHaveAttribute('href', '/treasury/instruments/1')
  })
})
