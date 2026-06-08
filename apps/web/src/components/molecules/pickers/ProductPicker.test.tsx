import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { ProductPicker, type ProductPickerValue } from './ProductPicker'

const mockApiGet = vi.hoisted(() => vi.fn<(url: string) => unknown>())

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
      meta: {
        total: Array.isArray(data) ? data.length : 0,
        current_page: 1,
        per_page: 20,
        last_page: 1,
      },
    },
  }
}

const oilFilter: ProductPickerValue = {
  id: '11111111-1111-4111-8111-111111111111',
  sku: 'FILT-OIL-STD',
  name: 'Filtre à huile standard',
  sale_price: '25.000',
  currency: 'TND',
}
const brakePad: ProductPickerValue = {
  id: '22222222-2222-4222-8222-222222222222',
  sku: 'BRAKE-PAD-FRONT',
  name: 'Plaquettes de frein (avant)',
  sale_price: '95.000',
  currency: 'TND',
}

describe('ProductPicker', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
  })

  it('renders a combobox when no value is set', () => {
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} />)
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('lists products on open before any typing (no min-character gate)', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter, brakePad]))
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)

    // Opening the picker must fetch and show products without requiring input.
    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    await waitFor(() => {
      expect(screen.getAllByRole('option').length).toBeGreaterThan(0)
    })
    const [url] = mockApiGet.mock.calls[0] as [string]
    expect(url).toContain('/products')
    expect(url).not.toContain('search=')
  })

  it('renders the field label in the selected state so it aligns with sibling fields', () => {
    renderWithProviders(
      <ProductPicker value={oilFilter} onChange={() => undefined} label="Product" />,
    )
    expect(screen.getByText('Product')).toBeInTheDocument()
  })

  it('exposes the placeholder as the combobox accessible name when no visible label', () => {
    renderWithProviders(
      <ProductPicker value={null} onChange={() => undefined} label="" placeholder="Select a product" />,
    )
    expect(screen.getByRole('combobox', { name: 'Select a product' })).toBeInTheDocument()
  })

  it('uses the visible label as the combobox accessible name', () => {
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} label="Product" />)
    expect(screen.getByRole('combobox', { name: 'Product' })).toBeInTheDocument()
  })

  it('debounces and issues a request scoped to type=part by default', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter]))
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'filt')

    await waitFor(() => {
      const urls = mockApiGet.mock.calls.map((c) => c[0])
      expect(urls.some((u) => u.includes('search=filt'))).toBe(true)
    })
    const searchUrl = mockApiGet.mock.calls
      .map((c) => c[0])
      .find((u) => u.includes('search=filt'))
    expect(searchUrl).toContain('/products')
    expect(searchUrl).toContain('type=part')
  })

  it('shows the empty state when no rows match', async () => {
    mockApiGet.mockResolvedValue(response([]))
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'xyz')

    await waitFor(() => {
      expect(screen.getByText(/no matching products/i)).toBeInTheDocument()
    })
  })

  it('calls onChange with the selected product via keyboard', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter, brakePad]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'pad')

    await waitFor(() => {
      expect(screen.getAllByRole('option').length).toBeGreaterThan(0)
    })
    ;(combo as HTMLInputElement).focus()
    await user.keyboard('{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith(oilFilter)
  })

  it('renders the selected value chip and clears it via the X button', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={oilFilter} onChange={onChange} />)

    expect(screen.getByText(/Filtre à huile standard/i)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /clear selection/i }))
    expect(onChange).toHaveBeenCalledWith(null)
  })
})
