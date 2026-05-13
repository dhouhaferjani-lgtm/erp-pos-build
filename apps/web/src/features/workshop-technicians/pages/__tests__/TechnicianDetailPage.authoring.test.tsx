import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth } from '@/test/seedAuth'
import { TechnicianDetailPage } from '../TechnicianDetailPage'
import type { TechnicianProfile } from '../../api/types'
import type {
  TechnicianCertification,
  TechnicianTimeEntry,
  TechnicianTimeOff,
} from '../../api/authoringTypes'

const TECH_ID = 'tech-1'

function buildProfile(): TechnicianProfile {
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
    weekly_schedule: {
      mon: [],
      tue: [],
      wed: [],
      thu: [],
      fri: [],
      sat: [],
      sun: [],
    },
    hire_date: '2020-01-15',
    employment_status: 'active',
    employee_code: 'A-42',
    notes: null,
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  }
}

function buildCertification(): TechnicianCertification {
  return {
    id: 'cert-1',
    technician_profile_id: TECH_ID,
    certification_name: 'ASE Master',
    issuing_body: 'ASE',
    certificate_number: 'MA-42',
    issued_at: '2024-06-01',
    expires_at: '2029-06-01',
    notes: null,
    created_at: '2026-01-01T00:00:00Z',
  }
}

function buildTimeOff(): TechnicianTimeOff {
  return {
    id: 'to-1',
    technician_profile_id: TECH_ID,
    starts_at: '2026-07-01T00:00:00.000Z',
    ends_at: '2026-07-05T23:59:59.000Z',
    reason_code: 'vacation',
    is_full_day: true,
    is_approved: false,
    approved_by_user_id: null,
    notes: null,
  }
}

function buildTimeEntry(
  overrides: Partial<TechnicianTimeEntry> = {},
): TechnicianTimeEntry {
  return {
    id: 'te-1',
    technician_profile_id: TECH_ID,
    company_id: 'c1',
    started_at: '2026-07-01T08:00:00.000Z',
    ended_at: '2026-07-01T12:00:00.000Z',
    duration_minutes: 240,
    entry_type: 'work_order',
    work_order_id: null,
    work_order_status: null,
    source: 'manual',
    recorded_by_user_id: null,
    notes: null,
    ...overrides,
  }
}

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockApiGetHelper = vi.hoisted(() => vi.fn())

interface ApiEnvelope<T> {
  data: { data: T }
}

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: mockApiPost,
      patch: mockApiPatch,
      delete: mockApiDelete,
    },
    apiGet: mockApiGetHelper,
    apiPost: vi.fn().mockImplementation(async (url: string, body: unknown) => {
      const r = (await mockApiPost(url, body)) as ApiEnvelope<unknown>
      return r.data.data
    }),
    apiPatch: vi.fn().mockImplementation(async (url: string, body: unknown) => {
      const r = (await mockApiPatch(url, body)) as ApiEnvelope<unknown>
      return r.data.data
    }),
    apiDelete: vi.fn().mockImplementation(() => Promise.resolve(undefined)),
  }
})

vi.mock('react-router-dom', async () => {
  const actual =
    await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: TECH_ID }),
  }
})

describe('TechnicianDetailPage — authoring integration', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockApiDelete.mockReset()
    mockApiGetHelper.mockReset()
    seedAuth()

    mockApiGetHelper.mockImplementation(async (url: string) => {
      if (url === `/workshop/technicians/${TECH_ID}`) {
        return buildProfile()
      }
      if (url === `/workshop/technicians/${TECH_ID}/certifications`) {
        return [buildCertification()]
      }
      if (url === `/workshop/technicians/${TECH_ID}/time-off`) {
        return [buildTimeOff()]
      }
      if (url === `/workshop/technicians/${TECH_ID}/time-entries`) {
        return [
          buildTimeEntry({ id: 'te-1' }),
          buildTimeEntry({
            id: 'te-2-locked',
            work_order_id: '11111111-1111-1111-1111-111111111111',
            work_order_status: 'completed',
          }),
        ]
      }
      return []
    })
  })

  it('renders the certifications tab and opens the modal via Add button', async () => {
    const user = userEvent.setup()
    renderWithProviders(<TechnicianDetailPage />)

    await waitFor(() => {
      expect(screen.getByText(/Alice Mechanic/i)).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /^Certifications$/i }))

    await waitFor(() => {
      expect(screen.getByText(/ASE Master/i)).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /^Add certification$/i }))
    expect(screen.getByTestId('certification-form-modal')).toBeInTheDocument()
  })

  it('renders the time-off tab and opens the modal via Add button', async () => {
    const user = userEvent.setup()
    renderWithProviders(<TechnicianDetailPage />)

    await waitFor(() => {
      expect(screen.getByText(/Alice Mechanic/i)).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /^Time off$/i }))

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /^Log time off$/i })).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /^Log time off$/i }))
    expect(screen.getByTestId('time-off-form-modal')).toBeInTheDocument()
  })

  it('renders the time-entries tab, disables edit/delete on locked rows, and opens the modal', async () => {
    const user = userEvent.setup()
    renderWithProviders(<TechnicianDetailPage />)

    await waitFor(() => {
      expect(screen.getByText(/Alice Mechanic/i)).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: /^Time entries$/i }))

    // Two rows render; the second is locked because its WO status is
    // `completed`. The lock badge should appear exactly once.
    await waitFor(() => {
      expect(screen.getAllByText(/Work order/i).length).toBeGreaterThan(0)
    })
    expect(screen.getByText(/^Locked$/i)).toBeInTheDocument()

    // Spec §5.4.1 literal: edit/delete must be disabled when the entry is
    // on a completed/invoiced WO. Scope the disabled assertion to the
    // locked row so we don't pick up action buttons from the first entry.
    const lockedBadge = screen.getByText(/^Locked$/i)
    const lockedRow = lockedBadge.closest('li') as HTMLLIElement
    expect(lockedRow).not.toBeNull()
    const lockedButtons = lockedRow.querySelectorAll('button')
    expect(lockedButtons.length).toBe(2)
    lockedButtons.forEach((btn) => {
      expect((btn as HTMLButtonElement).disabled).toBe(true)
    })

    // The first (non-locked) row's add button still opens the modal.
    await user.click(screen.getByRole('button', { name: /^Log time entry$/i }))
    expect(screen.getByTestId('time-entry-form-modal')).toBeInTheDocument()
  })
})
