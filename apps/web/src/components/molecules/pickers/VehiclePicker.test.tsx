import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { VehiclePicker, type VehiclePickerValue } from './VehiclePicker'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

function response<T>(data: T) {
  return {
    data: {
      data,
      meta: { total: Array.isArray(data) ? data.length : 0, current_page: 1, per_page: 20 },
    },
  }
}

const tundra: VehiclePickerValue = {
  id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
  license_plate: 'TN-123-ABC',
  brand: 'Toyota',
  model: 'Tundra',
  year: 2019,
}
const corolla: VehiclePickerValue = {
  id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb1',
  license_plate: 'TN-456-DEF',
  brand: 'Toyota',
  model: 'Corolla',
  year: 2022,
}

describe('VehiclePicker', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
  })

  it('debounces search and sends license_plate query to /vehicles', async () => {
    mockApiGet.mockResolvedValue(response([tundra]))
    const user = userEvent.setup()
    renderWithProviders(<VehiclePicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'TN-123')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).toContain('/vehicles')
    expect(url).toContain('search=TN-123')
  })

  it('scopes to a partner when partnerId is provided', async () => {
    mockApiGet.mockResolvedValue(response([tundra]))
    const user = userEvent.setup()
    const partnerId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'
    renderWithProviders(
      <VehiclePicker value={null} onChange={() => undefined} partnerId={partnerId} />,
    )

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'TN')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).toContain(`partner_id=${partnerId}`)
  })

  it('clears the current selection when partnerId changes', async () => {
    const onChange = vi.fn()
    const { rerender } = renderWithProviders(
      <VehiclePicker value={tundra} onChange={onChange} partnerId="p-1" />,
    )

    rerender(<VehiclePicker value={tundra} onChange={onChange} partnerId="p-2" />)

    await waitFor(() => {
      expect(onChange).toHaveBeenCalledWith(null)
    })
  })

  it('shows empty state when no vehicles match', async () => {
    mockApiGet.mockResolvedValue(response([]))
    const user = userEvent.setup()
    renderWithProviders(<VehiclePicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'zzz')

    await waitFor(() => {
      expect(screen.getByText(/no matching vehicles/i)).toBeInTheDocument()
    })
  })

  it('selects via keyboard ArrowDown + Enter', async () => {
    mockApiGet.mockResolvedValue(response([tundra, corolla]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<VehiclePicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Toy')

    await waitFor(() => {
      expect(screen.getAllByRole('option').length).toBeGreaterThanOrEqual(2)
    })
    ;(combo as HTMLInputElement).focus()
    await user.keyboard('{ArrowDown}{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith(corolla)
  })
})
