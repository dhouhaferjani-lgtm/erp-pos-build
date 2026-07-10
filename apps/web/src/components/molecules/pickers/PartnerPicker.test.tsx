import { afterEach, describe, it, expect, vi, beforeEach } from 'vitest'
import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { PartnerPicker, type PartnerPickerValue } from './PartnerPicker'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockCreatedPartner = vi.hoisted<PartnerPickerValue>(() => ({
  id: '33333333-3333-4333-8333-333333333333',
  name: 'New Partner',
  type: 'customer',
  email: null,
  city: null,
}))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

vi.mock('@/components/organisms/AddPartnerModal', () => ({
  AddPartnerModal: ({
    isOpen,
    onSuccess,
  }: {
    isOpen: boolean
    onSuccess?: (partner: PartnerPickerValue) => void
  }) => (
    isOpen ? (
      <div role="dialog" aria-label="add partner modal">
        <button type="button" onClick={() => { onSuccess?.(mockCreatedPartner) }}>
          Create partner
        </button>
      </div>
    ) : null
  ),
}))

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

describe('PartnerPicker', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    setTenant('tenant-1', 'company-1')
  })

  afterEach(() => {
    act(() => {
      resetTenant()
    })
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
      const [latestUrl] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
      expect(latestUrl).toContain('search=Acme')
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).toContain('/partners')
    expect(url).toContain('search=Acme')
    expect(url).toContain('type=customer')
    expect(url).toContain('is_active=true')
  })

  it('lists partners on open before any typing', async () => {
    mockApiGet.mockResolvedValue(response([acme]))
    const user = userEvent.setup()
    renderWithProviders(<PartnerPicker value={null} onChange={() => undefined} />)

    await user.click(screen.getByRole('combobox'))

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    await waitFor(() => {
      expect(screen.getByRole('option', { name: /Acme Auto/i })).toBeInTheDocument()
    })
    const [url] = mockApiGet.mock.calls[0] as [string]
    expect(url).toContain('/partners')
    expect(url).not.toContain('search=')
  })

  it('rehydrates a bare selected partner id for display', async () => {
    mockApiGet.mockResolvedValue(response(acme))

    renderWithProviders(<PartnerPicker value={acme.id} onChange={() => undefined} />)

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalledWith(`/partners/${acme.id}`)
    })
    expect(await screen.findByText(/Acme Auto/i)).toBeInTheDocument()
  })

  it('treats an empty string value as no selected partner', async () => {
    mockApiGet.mockResolvedValue(response([]))

    renderWithProviders(<PartnerPicker value="" onChange={() => undefined} />)

    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 0))
    })

    expect(mockApiGet).not.toHaveBeenCalled()
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('can include inactive partners when requested', async () => {
    mockApiGet.mockResolvedValue(response([acme]))
    const user = userEvent.setup()
    renderWithProviders(<PartnerPicker value={null} onChange={() => undefined} includeInactive />)

    const combo = screen.getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'Acme')

    await waitFor(() => {
      expect(mockApiGet).toHaveBeenCalled()
    })
    const [url] = mockApiGet.mock.calls[mockApiGet.mock.calls.length - 1] as [string]
    expect(url).not.toContain('is_active=true')
  })

  it('opens AddPartnerModal for inline creation and selects the created partner', async () => {
    mockApiGet.mockResolvedValue(response([]))
    const onChange = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(<PartnerPicker value={null} onChange={onChange} allowNewInline />)

    await user.click(screen.getByRole('combobox'))

    await waitFor(() => {
      expect(screen.getByText(/no matching partners/i)).toBeInTheDocument()
    })
    await user.click(screen.getByRole('button', { name: /add new customer/i }))

    expect(screen.getByRole('dialog', { name: /add partner modal/i })).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /create partner/i }))

    expect(onChange).toHaveBeenCalledWith({
      id: mockCreatedPartner.id,
      name: mockCreatedPartner.name,
      type: mockCreatedPartner.type,
    })
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
