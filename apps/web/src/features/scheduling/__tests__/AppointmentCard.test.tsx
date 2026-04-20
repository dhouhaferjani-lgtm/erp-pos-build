import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { AppointmentCard } from '../components/molecules/AppointmentCard'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('AppointmentCard', () => {
  it('displays the customer name as the primary label when present', () => {
    render(
      <AppointmentCard
        appointment={{
          id: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
          appointment_number: 'APT-2026-000001',
          status: 'scheduled',
          start: '2026-04-20T09:00:00Z',
          end: '2026-04-20T10:30:00Z',
          customer_display: 'Mohamed Ben Ali',
          bay_name: 'Bay 1',
          bay_code: 'B1',
        }}
      />,
    )
    expect(screen.getByText('Mohamed Ben Ali')).toBeInTheDocument()
    expect(screen.getByText('APT-2026-000001')).toBeInTheDocument()
    expect(screen.getByText('status.scheduled')).toBeInTheDocument()
  })

  it('falls back to appointment number when no customer display is set', () => {
    render(
      <AppointmentCard
        appointment={{
          id: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
          appointment_number: 'APT-2026-000002',
          status: 'confirmed',
          start: '2026-04-21T08:00:00Z',
          end: '2026-04-21T09:00:00Z',
          customer_display: null,
          bay_name: null,
          bay_code: null,
        }}
      />,
    )
    expect(screen.getByText('APT-2026-000002')).toBeInTheDocument()
    expect(screen.getByText('scheduler.unassignedBay')).toBeInTheDocument()
  })

  it('invokes onClick with the appointment id when clicked', () => {
    const onClick = vi.fn()
    render(
      <AppointmentCard
        onClick={onClick}
        appointment={{
          id: 'cccccccc-cccc-cccc-cccc-cccccccccccc',
          appointment_number: 'APT-2026-000003',
          status: 'checked_in',
          start: '2026-04-22T14:00:00Z',
          end: '2026-04-22T15:00:00Z',
          customer_display: 'Fatima Trabelsi',
          bay_name: 'Bay 2',
          bay_code: 'B2',
        }}
      />,
    )
    fireEvent.click(screen.getByRole('button'))
    expect(onClick).toHaveBeenCalledWith('cccccccc-cccc-cccc-cccc-cccccccccccc')
  })
})
