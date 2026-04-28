import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AppointmentFormDrawer } from '../components/organisms/AppointmentFormDrawer'
import type { Appointment, Bay } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: { defaultValue?: string }) => opts?.defaultValue ?? key,
  }),
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

const mockApiGet = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { get: mockApiGet } }
})

function renderDrawer(): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <AppointmentFormDrawer isOpen locationId="loc-1" onClose={() => undefined} />
    </QueryClientProvider>,
  )
}

describe('AppointmentFormDrawer', () => {
  beforeEach(() => {
    bookMock.mockReset()
    mockApiGet.mockReset()
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

  it('submits with partner + vehicle UUIDs when the picker flow is used', async () => {
    const partner = {
      id: '11111111-1111-4111-8111-111111111111',
      name: 'Mohamed Ben Ali',
      type: 'customer',
      email: 'mohamed@example.com',
      city: 'Tunis',
    }
    const vehicle = {
      id: '22222222-2222-4222-8222-222222222222',
      license_plate: 'TN-555-ZZZ',
      brand: 'Toyota',
      model: 'Hilux',
      year: 2020,
    }
    mockApiGet.mockImplementation((url: string) => {
      if (url.startsWith('/partners')) return Promise.resolve({ data: { data: [partner] } })
      if (url.startsWith('/vehicles')) return Promise.resolve({ data: { data: [vehicle] } })
      return Promise.resolve({ data: { data: [] } })
    })
    const user = userEvent.setup()
    renderDrawer()

    // Fill scheduled_start/end via label search (they stay plain inputs)
    const start = screen.getByText('fields.scheduledStart').parentElement?.querySelector('input')
    const end = screen.getByText('fields.scheduledEnd').parentElement?.querySelector('input')
    if (!(start instanceof HTMLInputElement) || !(end instanceof HTMLInputElement)) {
      throw new Error('schedule inputs missing')
    }
    fireEvent.change(start, { target: { value: '2026-04-25T09:00' } })
    fireEvent.change(end, { target: { value: '2026-04-25T10:00' } })

    // Pick partner via picker
    const partnerCombo = screen
      .getByTestId('appointment-customer-picker')
      .querySelector('input[role="combobox"]')
    if (!(partnerCombo instanceof HTMLInputElement)) {
      throw new Error('partner combobox missing')
    }
    await user.click(partnerCombo)
    await user.type(partnerCombo, 'Moh')
    await waitFor(() => {
      expect(screen.getByText('Mohamed Ben Ali')).toBeInTheDocument()
    })
    await user.click(screen.getByText('Mohamed Ben Ali'))

    // Now pick vehicle (the vehicle picker appears once customer is set)
    const vehicleCombo = await waitFor(() => {
      const el = screen
        .getByTestId('appointment-vehicle-picker')
        .querySelector('input[role="combobox"]')
      if (!(el instanceof HTMLInputElement) || el.disabled) {
        throw new Error('vehicle combobox not ready')
      }
      return el
    })
    await user.click(vehicleCombo)
    await user.type(vehicleCombo, 'TN')
    await waitFor(() => {
      expect(screen.getByText(/Toyota Hilux/)).toBeInTheDocument()
    })
    await user.click(screen.getByText(/Toyota Hilux/))

    fireEvent.click(screen.getByRole('button', { name: 'actions.book' }))

    expect(bookMock).toHaveBeenCalledTimes(1)
    const [payload] = bookMock.mock.calls[0] ?? []
    if (payload === null || typeof payload !== 'object') {
      throw new Error('booking payload must be an object')
    }
    const record: Record<string, unknown> = { ...payload }
    expect(record['customer_partner_id']).toBe(partner.id)
    expect(record['vehicle_id']).toBe(vehicle.id)
    expect(record['customer_name']).toBe('Mohamed Ben Ali')
    expect(record['vehicle_plate']).toBe('TN-555-ZZZ')
  })

  it('preserves the legacy free-text path when walk-in mode is enabled', async () => {
    const user = userEvent.setup()
    renderDrawer()

    await user.click(screen.getByTestId('appointment-walkin-toggle'))

    const setByLabel = (labelKey: string, value: string): void => {
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

    setByLabel('fields.customerName', '  Mohamed Ben Ali  ')
    setByLabel('fields.customerPhone', '+216 20 123 456')
    setByLabel('fields.scheduledStart', '2026-04-25T09:00')
    setByLabel('fields.scheduledEnd', '2026-04-25T10:00')

    fireEvent.click(screen.getByRole('button', { name: 'actions.book' }))

    expect(bookMock).toHaveBeenCalledTimes(1)
    const [payload] = bookMock.mock.calls[0] ?? []
    if (payload === null || typeof payload !== 'object') {
      throw new Error('booking payload must be an object')
    }
    const record: Record<string, unknown> = { ...payload }
    expect(record['customer_partner_id']).toBeNull()
    expect(record['vehicle_id']).toBeNull()
    expect(record['customer_name']).toBe('Mohamed Ben Ali')
    expect(record['customer_phone']).toBe('+216 20 123 456')
  })
})
