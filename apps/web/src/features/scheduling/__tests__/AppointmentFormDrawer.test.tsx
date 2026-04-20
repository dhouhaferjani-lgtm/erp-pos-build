import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { AppointmentFormDrawer } from '../components/organisms/AppointmentFormDrawer'
import type { Appointment, Bay } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const bookMock = vi.fn<(input: unknown, opts?: { onSuccess?: (appt: Appointment) => void }) => void>()

vi.mock('../hooks/useScheduling', () => ({
  useBays: (): { data: Bay[]; isLoading: false; isError: false } => ({
    isLoading: false,
    isError: false,
    data: [
      {
        id: 'bay-1',
        tenant_id: 't',
        company_id: 'c',
        location_id: 'loc-1',
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
    ],
  }),
  useBookAppointment: () => ({
    mutate: bookMock,
    isPending: false,
  }),
}))

describe('AppointmentFormDrawer', () => {
  beforeEach(() => {
    bookMock.mockReset()
  })

  it('returns null when closed', () => {
    const { container } = render(
      <AppointmentFormDrawer
        isOpen={false}
        locationId="loc-1"
        onClose={() => undefined}
      />,
    )
    expect(container.firstChild).toBeNull()
  })

  it('submits a valid booking payload with trimmed fields', () => {
    render(
      <AppointmentFormDrawer
        isOpen
        locationId="loc-1"
        onClose={() => undefined}
      />,
    )

    const setValue = (labelKey: string, value: string): void => {
      const input = screen.getByText(labelKey).parentElement?.querySelector(
        'input, select, textarea',
      )
      if (
        !(
          input instanceof HTMLInputElement ||
          input instanceof HTMLSelectElement ||
          input instanceof HTMLTextAreaElement
        )
      ) {
        throw new Error(`label ${labelKey} missing`)
      }
      fireEvent.change(input, { target: { value } })
    }

    setValue('fields.customerName', '  Mohamed Ben Ali  ')
    setValue('fields.customerPhone', '+216 20 123 456')
    setValue('fields.scheduledStart', '2026-04-25T09:00')
    setValue('fields.scheduledEnd', '2026-04-25T10:00')

    fireEvent.click(screen.getByRole('button', { name: 'actions.book' }))

    expect(bookMock).toHaveBeenCalledTimes(1)
    const [payload] = bookMock.mock.calls[0] ?? []
    // Narrow via runtime checks instead of `as` assertion.
    if (payload === null || typeof payload !== 'object') {
      throw new Error('booking payload must be an object')
    }
    const record: Record<string, unknown> = { ...payload }
    expect(record['location_id']).toBe('loc-1')
    expect(record['customer_name']).toBe('Mohamed Ben Ali')
    expect(record['estimated_duration_minutes']).toBe(60)
    const plannedServices = record['planned_services']
    if (!Array.isArray(plannedServices)) {
      throw new Error('planned_services must be an array')
    }
    expect(plannedServices.length).toBe(1)
  })
})
