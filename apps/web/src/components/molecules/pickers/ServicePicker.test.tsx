import { afterEach, describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { ServicePicker, type ServicePickerValue } from './ServicePicker'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

function response<T>(data: T) {
  return { data: { data } }
}

const oilChange: ServicePickerValue = {
  id: '33333333-3333-4333-8333-333333333333',
  code: 'LAB-OIL-CHANGE',
  name: 'Vidange + filtres',
  pricing_type: 'hourly',
  hourly_rate: '45.000',
  currency: 'TND',
}
const brakeFront: ServicePickerValue = {
  id: '44444444-4444-4444-8444-444444444444',
  code: 'LAB-BRAKE-FRONT',
  name: 'Remplacement plaquettes avant',
  pricing_type: 'hourly',
  hourly_rate: '45.000',
  currency: 'TND',
}

describe('ServicePicker', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders a combobox when no value is set', () => {
    renderWithProviders(<ServicePicker value={null} onChange={() => undefined} />)
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('debounces and issues a request to /services with search param', async () => {
    mockApiGet.mockResolvedValue(response([oilChange]))
    const user = userEvent.setup()
    renderWithProviders(<ServicePicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'oil')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).toContain('/services')
    expect(url).toContain('search=oil')
  })

  it('shows the empty state when no rows match', async () => {
    mockApiGet.mockResolvedValue(response([]))
    const user = userEvent.setup()
    renderWithProviders(<ServicePicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'xyz')

    await waitFor(() => {
      expect(screen.getByText(/no matching services/i)).toBeInTheDocument()
    })
  })

  it('calls onChange when the user picks a result via keyboard', async () => {
    mockApiGet.mockResolvedValue(response([oilChange, brakeFront]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ServicePicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'lab')

    await waitFor(() => {
      expect(screen.getAllByRole('option').length).toBeGreaterThan(0)
    })
    ;(combo as HTMLInputElement).focus()
    await user.keyboard('{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith(oilChange)
  })

  it('renders the selected value chip and clears it via the X button', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ServicePicker value={oilChange} onChange={onChange} />)

    expect(screen.getByText(/Vidange \+ filtres/i)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /clear selection/i }))
    expect(onChange).toHaveBeenCalledWith(null)
  })
})
