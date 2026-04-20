import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { DayViewBoard } from '../components/organisms/DayViewBoard'
import type { Bay, DayViewData } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../hooks/useScheduling', () => ({
  useBays: (): { data: Bay[]; isLoading: false; isError: false } => ({
    isLoading: false,
    isError: false,
    data: [
      {
        id: 'bay-1',
        tenant_id: 't',
        company_id: 'c',
        location_id: 'loc',
        code: 'B1',
        name: 'Bay 1',
        bay_type: 'general',
        display_order: 1,
        operating_hours: {},
        notes: null,
        is_active: true,
        created_at: '2026-01-01',
        updated_at: '2026-01-01',
      },
      {
        id: 'bay-2',
        tenant_id: 't',
        company_id: 'c',
        location_id: 'loc',
        code: 'B2',
        name: 'Alignment Bay',
        bay_type: 'alignment',
        display_order: 2,
        operating_hours: {},
        notes: null,
        is_active: true,
        created_at: '2026-01-01',
        updated_at: '2026-01-01',
      },
    ],
  }),
  useDayView: (): { data: DayViewData; isLoading: false; isError: false } => ({
    isLoading: false,
    isError: false,
    data: {
      date: '2026-04-20',
      availability: {},
      booked: {
        'bay-1': [
          {
            appointment_id: 'apt-aaa',
            start: '2026-04-20T09:00:00Z',
            end: '2026-04-20T10:30:00Z',
            status: 'confirmed',
          },
        ],
        'bay-2': [],
      },
    },
  }),
}))

describe('DayViewBoard', () => {
  it('renders one row per active bay with seeded appointments', () => {
    render(<DayViewBoard date="2026-04-20" />)
    // Both bays render a row header — Bay 1 also appears inside the
    // AppointmentCard's BayBadge, so the Bay 1 label is present multiple
    // times. Bay 2 appears only as a header.
    expect(screen.getAllByText('B1 · Bay 1').length).toBeGreaterThanOrEqual(1)
    expect(screen.getByText('B2 · Alignment Bay')).toBeInTheDocument()
    // Bay 1 has one seeded booking — its status badge renders
    expect(screen.getByText('status.confirmed')).toBeInTheDocument()
    // Bay 2 has none — empty-state key renders
    expect(screen.getByText('scheduler.emptyBay')).toBeInTheDocument()
  })
})
