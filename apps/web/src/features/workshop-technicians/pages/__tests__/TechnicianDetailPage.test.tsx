import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { TechnicianDetailPage } from '../TechnicianDetailPage'
import type { TechnicianProfile } from '../../api/types'

/** Minimal slice of the query result the page actually reads. */
interface TechQuerySlice {
  data: TechnicianProfile | undefined
  isLoading: boolean
  isError: boolean
  error: unknown
}

const TECH_ID = 'tech-1'

// Deterministic translations: render the key. Mirrors TechnicianRow.test.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', async () => {
  const actual =
    await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: TECH_ID }),
  }
})

const mockUseTechnician = vi.hoisted(() => vi.fn())
vi.mock('../../hooks/useTechnicians', () => ({
  useTechnician: mockUseTechnician,
}))

// Keep the test light: stub the heavy tab/child panels.
vi.mock('../../components/WeeklyScheduleView', () => ({
  WeeklyScheduleView: () => <div data-testid="weekly-schedule" />,
}))
vi.mock('../../components/CertificationsTab', () => ({
  CertificationsTab: () => <div data-testid="certifications-tab" />,
}))
vi.mock('../../components/TimeOffTab', () => ({
  TimeOffTab: () => <div data-testid="time-off-tab" />,
}))
vi.mock('../../components/TimeEntriesTab', () => ({
  TimeEntriesTab: () => <div data-testid="time-entries-tab" />,
}))

function makeProfile(overrides: Partial<TechnicianProfile> = {}): TechnicianProfile {
  return {
    id: TECH_ID,
    tenant_id: 't1',
    company_id: 'c1',
    user_id: 'u1',
    user_display_name: 'Alice Mechanic',
    user_email: 'alice@example.com',
    skill_level: 'senior',
    specialties: ['engine_mechanical'],
    currency: 'TND',
    weekly_schedule: { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [], sun: [] },
    hire_date: '2020-01-15',
    employment_status: 'active',
    employee_code: 'A-42',
    notes: null,
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
    ...overrides,
  }
}

function queryResult(partial: Partial<TechQuerySlice>): TechQuerySlice {
  return {
    data: undefined,
    isLoading: false,
    isError: false,
    error: null,
    ...partial,
  }
}

function renderPage() {
  return render(
    <MemoryRouter>
      <TechnicianDetailPage />
    </MemoryRouter>,
  )
}

describe('TechnicianDetailPage — canonicalized presentation', () => {
  beforeEach(() => {
    mockUseTechnician.mockReset()
  })

  it('renders the display name as the single canonical h1', () => {
    mockUseTechnician.mockReturnValue(queryResult({ data: makeProfile() }))
    renderPage()
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('Alice Mechanic')
  })

  it('renders the inactive StatusBadge only when the profile is inactive', () => {
    mockUseTechnician.mockReturnValue(queryResult({ data: makeProfile({ is_active: true }) }))
    const { rerender } = renderPage()
    expect(screen.queryByText('labels.inactive')).not.toBeInTheDocument()

    mockUseTechnician.mockReturnValue(queryResult({ data: makeProfile({ is_active: false }) }))
    rerender(
      <MemoryRouter>
        <TechnicianDetailPage />
      </MemoryRouter>,
    )
    expect(screen.getByText('labels.inactive')).toBeInTheDocument()
  })

  it('renders pay rates with tabular-nums when pay fields are present', () => {
    mockUseTechnician.mockReturnValue(
      queryResult({
        data: makeProfile({ hourly_cost_rate: '20.000', hourly_billing_rate: '50.000' }),
      }),
    )
    renderPage()
    const billing = screen.getByText(/50\.000/)
    expect(billing.className).toContain('tabular-nums')
  })

  it('hides the pay section when pay fields are absent', () => {
    const profile = makeProfile()
    delete profile.hourly_cost_rate
    delete profile.hourly_billing_rate
    mockUseTechnician.mockReturnValue(queryResult({ data: profile }))
    renderPage()
    expect(screen.queryByText('detail.pay')).not.toBeInTheDocument()
  })

  it('renders the error state through the canonical alert role', () => {
    mockUseTechnician.mockReturnValue(
      queryResult({ isError: true, error: new Error('nope') }),
    )
    renderPage()
    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('team.errorLoading')
    expect(alert).toHaveTextContent('nope')
  })

  it('renders the loading state', () => {
    mockUseTechnician.mockReturnValue(queryResult({ isLoading: true }))
    renderPage()
    expect(screen.getByText('team.loading')).toBeInTheDocument()
  })
})
