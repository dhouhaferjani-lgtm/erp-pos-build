import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { TechnicianRow } from '../TechnicianRow'
import type { TechnicianProfile } from '../../api/types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
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

describe('TechnicianRow', () => {
  it('renders name and email when user_email is present', () => {
    const profile = makeProfile()
    render(
      <MemoryRouter>
        <TechnicianRow profile={profile} />
      </MemoryRouter>
    )
    expect(screen.getByText('Alice Wrench')).toBeInTheDocument()
    expect(screen.getByText('alice@example.com')).toBeInTheDocument()
  })

  it('hides hourly billing section when pay permission is absent (field missing from DTO)', () => {
    // Backend masks pay fields out entirely — presence of the key, not
    // null-vs-string, is the signal. A profile payload that lacks
    // `hourly_billing_rate` (i.e. the caller has no view_pay permission) must
    // not render the billing block.
    const profile = makeProfile()
    delete profile.hourly_billing_rate
    render(
      <MemoryRouter>
        <TechnicianRow profile={profile} />
      </MemoryRouter>
    )
    expect(screen.queryByText('labels.hourlyBillingRate')).not.toBeInTheDocument()
  })

  it('shows hourly billing when pay permission granted (field present in DTO)', () => {
    const profile = makeProfile({ hourly_billing_rate: '50.000' })
    render(
      <MemoryRouter>
        <TechnicianRow profile={profile} />
      </MemoryRouter>
    )
    expect(screen.getByText('labels.hourlyBillingRate')).toBeInTheDocument()
    expect(screen.getByText(/50\.000/)).toBeInTheDocument()
  })
})
