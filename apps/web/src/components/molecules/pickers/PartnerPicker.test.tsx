import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { PartnerPicker, type PartnerPickerValue } from './PartnerPicker'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

function response<T>(data: T) {
  return { data: { data, meta: { total: Array.isArray(data) ? data.length : 0, current_page: 1, per_page: 20, last_page: 1 } } }
}

const acme: PartnerPickerValue = {
  id: '11111111-1111-4111-8111-111111111111',
  name: 'Acme Auto',
  type: 'customer',
  email: 'contact@acme.auto',
  city: 'Tunis',
}
const globex: PartnerPickerValue = {
  id: '22222222-2222-4222-8222-222222222222',
  name: 'Globex Fleet',
  type: 'customer',
  email: 'ops@globex.tn',
  city: 'Sousse',
}

describe('PartnerPicker', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
  })

  it('renders the trigger and label', () => {
    renderWithProviders(<PartnerPicker value={null} onChange={() => undefined} />)
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('debounces the search and issues a request after the user types', async () => {
    mockApiGet.mockResolvedValue(response([acme]))
    const user = userEvent.setup()
    renderWithProviders(<PartnerPicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Acme')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).toContain('/partners')
    expect(url).toContain('search=Acme')
    expect(url).toContain('type=customer')
  })

  it('shows the empty state when the backend returns no rows', async () => {
    mockApiGet.mockResolvedValue(response([]))
    const user = userEvent.setup()
    renderWithProviders(<PartnerPicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Zzz')

    await waitFor(() => {
      expect(screen.getByText(/no matching partners/i)).toBeInTheDocument()
    })
  })

  it('calls onChange when the user picks a result via keyboard', async () => {
    mockApiGet.mockResolvedValue(response([acme, globex]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<PartnerPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'auto')

    await waitFor(() => {
      expect(screen.getAllByRole('option').length).toBeGreaterThan(0)
    })
    // Keyboard events target document.activeElement — ensure the combobox
    // still has focus before navigating.
    ;(combo as HTMLInputElement).focus()
    await user.keyboard('{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith(acme)
  })

  it('renders the selected value as a chip and clears it via the clear button', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<PartnerPicker value={acme} onChange={onChange} />)

    expect(screen.getByText(/Acme Auto/i)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /clear selection/i }))
    expect(onChange).toHaveBeenCalledWith(null)
  })
})
