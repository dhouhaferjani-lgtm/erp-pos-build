import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { WorkOrderCreatePage } from '../pages/WorkOrderCreatePage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: { defaultValue?: string }) => opts?.defaultValue ?? key,
  }),
}))

const createMock = vi.fn()
vi.mock('../hooks/useWorkOrders', () => ({
  useCreateWorkOrder: () => ({
    mutate: createMock,
    isPending: false,
  }),
}))

const mockApiGet = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: { get: mockApiGet } }
})

function renderPage(): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WorkOrderCreatePage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkOrderCreatePage', () => {
  beforeEach(() => {
    createMock.mockReset()
    mockApiGet.mockReset()
  })

  it('keeps the vehicle picker disabled until a customer is chosen', () => {
    renderPage()
    const vehicle = screen
      .getByTestId('work-order-vehicle-picker')
      .querySelector('input[role="combobox"]')
    if (!(vehicle instanceof HTMLInputElement)) {
      throw new Error('vehicle picker missing')
    }
    expect(vehicle.disabled).toBe(true)
  })

  it('submits with the selected customer_partner_id + vehicle_id', async () => {
    const partner = {
      id: '11111111-1111-4111-8111-111111111111',
      name: 'Alpha Garage',
      type: 'customer',
      email: 'a@g.com',
      city: 'Tunis',
    }
    const vehicle = {
      id: '22222222-2222-4222-8222-222222222222',
      license_plate: 'TN-777-ABC',
      brand: 'Renault',
      model: 'Clio',
      year: 2021,
    }
    mockApiGet.mockImplementation((url: string) => {
      if (url.startsWith('/partners')) return Promise.resolve({ data: { data: [partner] } })
      if (url.startsWith('/vehicles')) return Promise.resolve({ data: { data: [vehicle] } })
      return Promise.resolve({ data: { data: [] } })
    })
    const user = userEvent.setup()
    renderPage()

    // Pick partner
    const partnerCombo = screen
      .getByTestId('work-order-customer-picker')
      .querySelector('input[role="combobox"]')
    if (!(partnerCombo instanceof HTMLInputElement)) {
      throw new Error('partner combobox missing')
    }
    await user.click(partnerCombo)
    await user.type(partnerCombo, 'Alp')
    await waitFor(() => {
      expect(screen.getByText('Alpha Garage')).toBeInTheDocument()
    })
    await user.click(screen.getByText('Alpha Garage'))

    // Vehicle combobox now enabled
    const vehicleCombo = screen
      .getByTestId('work-order-vehicle-picker')
      .querySelector('input[role="combobox"]')
    if (!(vehicleCombo instanceof HTMLInputElement)) {
      throw new Error('vehicle combobox missing')
    }
    await user.click(vehicleCombo)
    await user.type(vehicleCombo, 'TN')
    await waitFor(() => {
      expect(screen.getByText(/Renault Clio/)).toBeInTheDocument()
    })
    await user.click(screen.getByText(/Renault Clio/))

    fireEvent.click(screen.getByRole('button', { name: 'actions.create' }))

    expect(createMock).toHaveBeenCalledTimes(1)
    const [payload] = createMock.mock.calls[0] ?? []
    if (payload === null || typeof payload !== 'object') {
      throw new Error('payload must be object')
    }
    const record: Record<string, unknown> = { ...payload }
    expect(record['customer_partner_id']).toBe(partner.id)
    expect(record['vehicle_id']).toBe(vehicle.id)
  })
})
