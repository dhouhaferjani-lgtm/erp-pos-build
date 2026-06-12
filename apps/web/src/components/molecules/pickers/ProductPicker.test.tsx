import { afterEach, describe, it, expect, vi, beforeEach } from 'vitest'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
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
  requires_batch_tracking: true,
}
const brakePad: ProductPickerValue = {
  id: '22222222-2222-4222-8222-222222222222',
  sku: 'BRAKE-PAD-FRONT',
  name: 'Plaquettes de frein (avant)',
  sale_price: '95.000',
  currency: 'TND',
}
const pieceProduct: ProductPickerValue = {
  id: '33333333-3333-4333-8333-333333333333',
  sku: 'PCS-001',
  name: 'Piece product',
  sale_price: null,
  currency: null,
  quantity_decimals: 0,
}

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

describe('ProductPicker', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    setTenant('tenant-1', 'company-1')
  })

  afterEach(() => {
    act(() => {
      resetTenant()
    })
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
    if (!(combo instanceof HTMLInputElement)) {
      throw new Error('Expected ProductPicker combobox to render an input element')
    }
    combo.focus()
    await user.keyboard('{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith(oilFilter)
  })

  it('preserves the batch-tracking flag from product list results', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={onChange} />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)

    await waitFor(() => {
      expect(screen.getAllByRole('option').length).toBeGreaterThan(0)
    })
    ;(combo as HTMLInputElement).focus()
    await user.keyboard('{ArrowDown}{Enter}')

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({
      id: oilFilter.id,
      requires_batch_tracking: true,
    }))
  })

  it('renders the selected value chip and clears it via the X button', async () => {
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={oilFilter} onChange={onChange} />)

    expect(screen.getByText(/Filtre à huile standard/i)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /clear selection/i }))
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('preserves product quantity decimals when selecting an option', async () => {
    mockApiGet.mockResolvedValue(response([pieceProduct]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={onChange} />)

    await user.click(screen.getByRole('combobox'))
    await user.click(await screen.findByRole('option', { name: /PCS-001.*Piece product/ }))

    expect(onChange).toHaveBeenCalledWith(
      expect.objectContaining({
        id: pieceProduct.id,
        quantity_decimals: 0,
      }),
    )
  })

  it('renders the open dropdown in a portal so overflow-hidden ancestors cannot clip it', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter]))
    const user = userEvent.setup()
    const { container } = renderWithProviders(
      <div className="overflow-hidden" data-testid="clipping-wrapper">
        <ProductPicker value={null} onChange={() => undefined} />
      </div>,
    )

    await user.click(screen.getByRole('combobox'))
    const listbox = await screen.findByRole('listbox')

    // The listbox must escape the React subtree (portal to document.body);
    // otherwise overflow-hidden/overflow-x-auto table wrappers clip it.
    expect(container.contains(listbox)).toBe(false)
    expect(document.body.contains(listbox)).toBe(true)
  })

  it('closes the portaled dropdown on outside click but not on dropdown click', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter, brakePad]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={onChange} />)

    await user.click(screen.getByRole('combobox'))
    await screen.findByRole('listbox')

    // Clicking an option (inside the portal, outside the container) must
    // select it, not be treated as an outside click.
    await user.click(screen.getByRole('option', { name: /FILT-OIL-STD/ }))
    expect(onChange).toHaveBeenCalledWith(oilFilter)

    mockApiGet.mockResolvedValue(response([oilFilter, brakePad]))
    await user.click(screen.getByRole('combobox'))
    await screen.findByRole('listbox')
    await user.click(document.body)
    await waitFor(() => {
      expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })
  })

  it('anchors the portaled dropdown to the input rect and repositions on scroll', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter]))
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} />)

    const combo = screen.getByRole('combobox')
    // jsdom returns zero rects by default, which would let positioning bugs
    // pass silently — mock a realistic input rect instead.
    const rect = { top: 100, bottom: 132, left: 40, right: 360, width: 320, height: 32, x: 40, y: 100, toJSON: () => ({}) }
    vi.spyOn(combo, 'getBoundingClientRect').mockReturnValue(rect as DOMRect)

    await user.click(combo)
    const listbox = await screen.findByRole('listbox')

    expect(listbox).toHaveStyle({ top: '136px', left: '40px', width: '320px' })

    // Simulate an ancestor scrolling the input 50px up — the capture-phase
    // scroll listener must re-anchor the dropdown.
    vi.spyOn(combo, 'getBoundingClientRect').mockReturnValue({ ...rect, top: 50, bottom: 82, y: 50 } as DOMRect)
    act(() => {
      window.dispatchEvent(new Event('scroll'))
    })
    await waitFor(() => {
      expect(listbox).toHaveStyle({ top: '86px' })
    })
  })

  it('removes scroll and resize listeners when the dropdown closes', async () => {
    mockApiGet.mockResolvedValue(response([oilFilter]))
    const user = userEvent.setup()
    const addSpy = vi.spyOn(window, 'addEventListener')
    const removeSpy = vi.spyOn(window, 'removeEventListener')
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} />)

    await user.click(screen.getByRole('combobox'))
    await screen.findByRole('listbox')

    expect(addSpy).toHaveBeenCalledWith('scroll', expect.any(Function), true)
    expect(addSpy).toHaveBeenCalledWith('resize', expect.any(Function))

    await user.keyboard('{Escape}')
    await waitFor(() => {
      expect(screen.queryByRole('listbox')).not.toBeInTheDocument()
    })

    expect(removeSpy).toHaveBeenCalledWith('scroll', expect.any(Function), true)
    expect(removeSpy).toHaveBeenCalledWith('resize', expect.any(Function))
    addSpy.mockRestore()
    removeSpy.mockRestore()
  })

  it('refetches open results when the active company changes', async () => {
    mockApiGet.mockResolvedValue(response([pieceProduct]))
    const user = userEvent.setup()
    renderWithProviders(<ProductPicker value={null} onChange={() => undefined} />)

    await user.click(screen.getByRole('combobox'))

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledTimes(1)
    })

    act(() => {
      setTenant('tenant-1', 'company-2')
    })

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledTimes(2)
    })
  })
})
