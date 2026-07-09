import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, cleanup, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

// Keep the real getErrorMessage/isApiError implementations (they only need
// axios' isAxiosError shape check) — only apiGet/apiPost are stubbed, so the
// component's error-handling path is exercised for real, not mocked away.
vi.mock('@/lib/api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/api')>()
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
  }
})

import { AddQuickProductModal, type AddQuickProductModalProps } from './AddQuickProductModal'

const TAX_CONFIG = {
  id: 'tax-1',
  name: 'TVA 19%',
  tax_type: 'PERCENTAGE',
  percentage_rate: '19.00',
  fixed_amount: null,
  applies_to: 'LINE_ITEMS',
  applicable_document_types: [],
  is_active: true,
  is_default: true,
  country_code: 'TN',
  code: 'TVA19',
  sequence_order: 1,
  stacks_on: 'BASE_AMOUNT',
  is_stamp_duty: false,
  is_recoverable: true,
  created_at: '',
  updated_at: '',
}

function buildValidationError(errors: Record<string, string[]>) {
  return {
    isAxiosError: true,
    response: {
      status: 422,
      data: {
        error: {
          code: 'VALIDATION_ERROR',
          message: 'The given data was invalid.',
          errors,
        },
      },
    },
  }
}

function buildModal(props: Partial<AddQuickProductModalProps>) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  return (
    <QueryClientProvider client={queryClient}>
      <AddQuickProductModal
        isOpen={props.isOpen ?? true}
        onClose={props.onClose ?? (() => undefined)}
        {...(props.onSuccess !== undefined && { onSuccess: props.onSuccess })}
        {...(props.prefill !== undefined && { prefill: props.prefill })}
      />
    </QueryClientProvider>
  )
}

function renderModal(props: Partial<AddQuickProductModalProps>) {
  return render(buildModal(props))
}

describe('AddQuickProductModal prefill', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    // Tax configuration select needs at least one option to be selectable;
    // every other apiGet call (e.g. products list) keeps the prior empty default.
    mockApiGet.mockImplementation((url: string) =>
      Promise.resolve(url.includes('taxation/configurations') ? [TAX_CONFIG] : []))
    window.localStorage.setItem('autoerp-language', 'en')
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Admin User',
        email: 'admin@example.test',
        tenant_id: 'tenant-1',
        roles: ['admin'],
        email_verified_at: '2026-01-01T00:00:00.000Z',
      },
      token: 'token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
  })

  afterEach(() => {
    cleanup()
  })

  it('seeds name, sale_price and tax_rate from prefill when opened', async () => {
    renderModal({
      isOpen: true,
      prefill: { name: 'Doliprane 1g', sale_price: '4.850', cost: '4.850', tax_rate: '7' },
    })
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('Doliprane 1g')
    // sale_price is a type="number" input — jest-dom reports blank/populated
    // number inputs as a JS number (or null when blank), not the raw string.
    expect(screen.getByLabelText(/sale price/i)).toHaveValue(4.85)
    // sku stays empty — never prefilled
    expect(screen.getByLabelText(/^sku/i)).toHaveValue('')
  })

  it('behaves exactly as before when prefill is absent (all fields empty)', async () => {
    renderModal({ isOpen: true })
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('')
    expect(screen.getByLabelText(/sale price/i)).toHaveValue(null)
  })

  it('re-seeds from prefill each time the modal reopens', async () => {
    const user = userEvent.setup()
    const { rerender } = renderModal({ isOpen: true, prefill: { name: 'A' } })
    // user edits then closes
    await user.clear(await screen.findByLabelText(/^name/i))
    rerender(buildModal({ isOpen: false, prefill: { name: 'A' } }))
    rerender(buildModal({ isOpen: true, prefill: { name: 'A' } }))
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('A')
  })

  it('does not wipe user edits when the modal stays open and a caller passes a referentially-new but value-identical prefill (e.g. inline buildProductPrefill(line) on parent re-render)', async () => {
    const user = userEvent.setup()
    const { rerender } = renderModal({ isOpen: true, prefill: { name: 'A' } })
    const nameInput = await screen.findByLabelText(/^name/i)
    await user.clear(nameInput)
    await user.type(nameInput, 'Edited')
    // Same value, but a brand-new object reference — simulates an inline
    // buildProductPrefill(line) call on an unrelated parent re-render while
    // the modal remains open.
    rerender(buildModal({ isOpen: true, prefill: { name: 'A' } }))
    expect(screen.getByLabelText(/^name/i)).toHaveValue('Edited')
  })
})

describe('AddQuickProductModal submit', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiGet.mockImplementation((url: string) =>
      Promise.resolve(url.includes('taxation/configurations') ? [TAX_CONFIG] : []))
    window.localStorage.setItem('autoerp-language', 'en')
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Admin User',
        email: 'admin@example.test',
        tenant_id: 'tenant-1',
        roles: ['admin'],
        email_verified_at: '2026-01-01T00:00:00.000Z',
      },
      token: 'token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
  })

  afterEach(() => {
    cleanup()
  })

  it('auto-generates a client-side SKU from the product name when SKU is left blank (server requires sku and does not auto-generate one)', async () => {
    const user = userEvent.setup()
    mockApiPost.mockResolvedValue({
      id: 'p1',
      name: 'Normogaz LM but/prop',
      sku: 'IGNORED-BY-TEST',
      is_physical: true,
      sale_price: 10,
      cost_price: 0,
      tax_rate: 19,
    })

    renderModal({ isOpen: true })
    await user.type(await screen.findByLabelText(/^name/i), 'Normogaz LM but/prop')
    await user.type(screen.getByLabelText(/sale price/i), '10')
    await user.selectOptions(screen.getByRole('combobox'), 'tax-1')
    await user.click(screen.getByRole('button', { name: /create/i }))

    await waitFor(() => { expect(mockApiPost).toHaveBeenCalledTimes(1) })
    const payload: unknown = mockApiPost.mock.calls[0]?.[1]
    if (typeof payload !== 'object' || payload === null || !('sku' in payload)) {
      throw new Error('apiPost was not called with a payload containing sku')
    }
    expect(payload.sku).toMatch(/^NORMOGAZ-LM/)
  })

  it('posts exactly the SKU the user typed, unchanged', async () => {
    const user = userEvent.setup()
    mockApiPost.mockResolvedValue({
      id: 'p2',
      name: 'Widget',
      sku: 'WID-100',
      is_physical: true,
      sale_price: 5,
      cost_price: 0,
      tax_rate: 19,
    })

    renderModal({ isOpen: true })
    await user.type(await screen.findByLabelText(/^name/i), 'Widget')
    await user.type(screen.getByLabelText(/^sku/i), 'WID-100')
    await user.type(screen.getByLabelText(/sale price/i), '5')
    await user.selectOptions(screen.getByRole('combobox'), 'tax-1')
    await user.click(screen.getByRole('button', { name: /create/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/products', expect.objectContaining({
        sku: 'WID-100',
      }))
    })
  })

  it('shows the server error message and keeps the modal open when the create request 422s', async () => {
    const user = userEvent.setup()
    mockApiPost.mockRejectedValue(
      buildValidationError({ sku: ['The sku has already been taken.'] }))
    const onClose = vi.fn()

    renderModal({ isOpen: true, onClose })
    await user.type(await screen.findByLabelText(/^name/i), 'Widget')
    await user.type(screen.getByLabelText(/sale price/i), '5')
    await user.selectOptions(screen.getByRole('combobox'), 'tax-1')
    await user.click(screen.getByRole('button', { name: /create/i }))

    // General envelope message surfaces in the modal...
    expect(await screen.findByText('The given data was invalid.')).toBeInTheDocument()
    // ...and the field-level message is mapped onto the SKU field.
    expect(screen.getByText('The sku has already been taken.')).toBeInTheDocument()
    // The modal must stay open on error — never silently close.
    expect(onClose).not.toHaveBeenCalled()
  })
})
