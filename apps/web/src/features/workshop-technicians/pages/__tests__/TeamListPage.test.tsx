import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { TeamListPage } from '../TeamListPage'
import type { TechnicianProfile } from '../../api/types'

/** Minimal slice of the query result the page actually reads. */
interface TechListQuerySlice {
  data: TechnicianProfile[] | undefined
  isLoading: boolean
  isError: boolean
  error: unknown
}

// Deterministic translations: render the key so assertions don't depend on
// the i18n bundle. Mirrors the existing TechnicianRow.test setup.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const mockUseTechnicians = vi.hoisted(() => vi.fn())
vi.mock('../../hooks/useTechnicians', () => ({
  useTechnicians: mockUseTechnicians,
}))

function makeProfile(overrides: Partial<TechnicianProfile> = {}): TechnicianProfile {
  return {
    id: '11111111-1111-1111-1111-111111111111',
    tenant_id: '22222222-2222-2222-2222-222222222222',
    company_id: '33333333-3333-3333-3333-333333333333',
    user_id: '44444444-4444-4444-4444-444444444444',
    user_display_name: 'Alice Wrench',
    user_email: 'alice@example.com',
    skill_level: 'senior',
    specialties: ['engine_mechanical'],
    currency: 'EUR',
    weekly_schedule: { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [], sun: [] },
    hire_date: '2024-01-15',
    employment_status: 'active',
    employee_code: 'EMP-001',
    notes: null,
    is_active: true,
    created_at: '2024-01-15T00:00:00Z',
    updated_at: '2024-01-15T00:00:00Z',
    ...overrides,
  }
}

function queryResult(partial: Partial<TechListQuerySlice>): TechListQuerySlice {
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
      <TeamListPage />
    </MemoryRouter>,
  )
}

describe('TeamListPage — canonicalized presentation', () => {
  beforeEach(() => {
    mockUseTechnicians.mockReset()
  })

  it('renders the canonical PageHeader title as the single h1', () => {
    mockUseTechnicians.mockReturnValue(queryResult({ data: [] }))
    renderPage()
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('team.title')
  })

  it('renders a technician row for each profile', () => {
    mockUseTechnicians.mockReturnValue(
      queryResult({ data: [makeProfile(), makeProfile({ id: 'id-2', user_display_name: 'Bob Bolt' })] }),
    )
    renderPage()
    expect(screen.getByText('Alice Wrench')).toBeInTheDocument()
    expect(screen.getByText('Bob Bolt')).toBeInTheDocument()
  })

  it('renders the empty state when there are no technicians', () => {
    mockUseTechnicians.mockReturnValue(queryResult({ data: [] }))
    renderPage()
    expect(screen.getByText('team.empty.title')).toBeInTheDocument()
    expect(screen.getByText('team.empty.body')).toBeInTheDocument()
  })

  it('renders the error state through the canonical alert role', () => {
    mockUseTechnicians.mockReturnValue(
      queryResult({ isError: true, error: new Error('boom') }),
    )
    renderPage()
    const alert = screen.getByRole('alert')
    expect(alert).toHaveTextContent('team.errorLoading')
    expect(alert).toHaveTextContent('boom')
  })

  it('uses the tokenized checkbox (not a bespoke off-theme class)', () => {
    mockUseTechnicians.mockReturnValue(queryResult({ data: [] }))
    renderPage()
    const checkbox = screen.getByRole('checkbox')
    // tokens.checkbox.base is the theme-bridged token; bespoke sky-* is gone.
    expect(checkbox.className).not.toMatch(/sky-/)
  })
})
