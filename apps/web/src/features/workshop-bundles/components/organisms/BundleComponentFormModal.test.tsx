import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor, fireEvent, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, AxiosHeaders } from 'axios'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { BundleComponentFormModal } from './BundleComponentFormModal'
import type { ServiceBundleComponentData, ServiceBundleData } from '../../types'

// Auto-selects a nested bundle on mount so the cycle-branch test can drive
// the submit path without emulating the full BundlePickerModal flow. The
// stub fires `onChange` once on mount when `value` is null to mirror the
// real controlled picker lifecycle (user picks → onChange fires once).
vi.mock('@/components/molecules/pickers', async () => {
  const actual = await vi.importActual<
    typeof import('@/components/molecules/pickers')
  >('@/components/molecules/pickers')
  const { useEffect } = await vi.importActual<typeof import('react')>('react')
  return {
    ...actual,
    BundlePicker: ({
      value,
      onChange,
    }: {
      value: unknown
      onChange: (next: unknown) => void
    }) => {
      useEffect(() => {
        if (value === null) {
          onChange({
            id: 'nested-bundle-1',
            code: 'NESTED',
            name: 'Nested bundle',
            description: null,
            pricing_mode: 'standard',
            base_price: null,
            currency: 'TND',
            service_interval_km: null,
            service_interval_months: null,
            estimated_labor_hours: null,
            component_count: 0,
          })
        }
        // Only run once on mount — the real picker doesn't auto-reselect.
        // eslint-disable-next-line react-hooks/exhaustive-deps
      }, [])
      return (
        <div data-testid="bundle-picker-stub">
          nested: {(value as { name?: string } | null)?.name ?? 'none'}
        </div>
      )
    },
  }
})

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet, post: mockApiPost, patch: mockApiPatch },
    apiGet: vi.fn().mockImplementation(async (url: string) => {
      if (url === '/uom/units') {
        return [
          { id: 'unit-each', code: 'EA', name: 'Each', symbol: 'EA' },
          { id: 'unit-hour', code: 'HR', name: 'Hour', symbol: 'HR' },
        ]
      }
      return []
    }),
  }
})

const bundle: ServiceBundleData = {
  id: 'bundle-1',
  tenant_id: 't1',
  company_id: 'c1',
  code: 'TEST-BUNDLE',
  name: 'Test bundle',
  description: null,
  pricing_mode: 'standard',
  base_price: null,
  currency: 'TND',
  tax_rate: '19.000',
  estimated_labor_hours: null,
  service_interval_km: null,
  service_interval_months: null,
  is_active: true,
  components: [],
  vehicle_applicabilities: [],
  created_at: '2026-04-20T00:00:00Z',
  updated_at: null,
}

function listResponse<T>(data: T[]) {
  return { data: { data } }
}

describe('BundleComponentFormModal', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  it('renders the create title and a part picker by default', () => {
    renderWithProviders(
      <BundleComponentFormModal bundle={bundle} onClose={() => undefined} onSaved={() => undefined} />,
    )
    expect(screen.getByText(/Add component/i)).toBeInTheDocument()
    expect(screen.getByTestId('product-picker')).toBeInTheDocument()
  })

  it('swaps the entity picker when component_type changes', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <BundleComponentFormModal bundle={bundle} onClose={() => undefined} onSaved={() => undefined} />,
    )

    const typeSelect = screen.getByTestId('bundle-component-type-select') as HTMLSelectElement
    await user.selectOptions(typeSelect, 'labor')
    expect(screen.getByTestId('service-picker')).toBeInTheDocument()
  })

  it('POSTs a new component on submit and fires onSaved', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.startsWith('/products')) {
        return Promise.resolve(listResponse([
          { id: 'prod-1', sku: 'FILT-OIL-STD', name: 'Oil filter', sale_price: '25.000', currency: 'TND', quantity_decimals: 3 },
        ]))
      }
      return Promise.resolve(listResponse([]))
    })
    mockApiPost.mockResolvedValue({
      data: {
        data: {
          id: 'comp-1',
          bundle_id: bundle.id,
          component_type: 'part',
          component_id: 'prod-1',
          component_display_name: 'Oil filter',
          quantity: '2.000',
          unit: 'EA',
          override_unit_price: null,
          is_optional: false,
          display_order: 0,
          notes: null,
        },
      },
    })

    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <BundleComponentFormModal bundle={bundle} onClose={() => undefined} onSaved={onSaved} />,
    )

    // pick a product
    const productPicker = screen.getByTestId('product-picker')
    const searchInput = productPicker.querySelector('input[role="combobox"]') as HTMLInputElement
    await user.click(searchInput)
    await user.type(searchInput, 'oil')
    // Wait until the picker's listbox is populated. Scoping with `within` is
    // critical here: the modal also contains a native <select> whose
    // <option> children expose role="option", which would satisfy a
    // root-level getAllByRole('option') check before the picker had
    // resolved. The listbox is portaled to document.body (so table/modal
    // overflow can't clip it), so resolve it through the combobox's
    // aria-controls wiring rather than as a descendant of the picker.
    const resolveListbox = (): HTMLElement | null => {
      const listboxId = searchInput.getAttribute('aria-controls')
      if (listboxId === null) return null
      return document.getElementById(listboxId)
    }
    await waitFor(() => {
      const lb = resolveListbox()
      expect(lb !== null && within(lb).queryAllByRole('option').length > 0).toBe(true)
    })
    // The native <select> for component_type ALSO exposes role="option" on
    // its <option> children. Scope the query to the picker's own listbox so
    // we don't accidentally click "Part".
    const listbox = resolveListbox() as HTMLElement
    const options = within(listbox).getAllByRole('option')
    fireEvent.click(options[0])

    await waitFor(() => {
      expect(screen.getByText(/Oil filter/i)).toBeInTheDocument()
    })

    // set quantity + unit
    const quantityInput = screen.getByTestId('bundle-component-quantity') as HTMLInputElement
    expect(quantityInput).toHaveAttribute('type', 'number')
    expect(quantityInput).toHaveAttribute('step', '0.001')
    fireEvent.change(quantityInput, { target: { value: '2.000' } })
    expect(quantityInput).toHaveAttribute('value', '2.000')

    const unitSelect = screen.getByTestId('bundle-component-unit-select') as HTMLSelectElement
    await waitFor(() => {
      expect(unitSelect.options.length).toBeGreaterThan(1)
    })
    fireEvent.change(unitSelect, { target: { value: 'unit-each' } })

    const submit = screen.getByRole('button', { name: /save component/i })
    await user.click(submit)

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
    })
    const [url, payload] = mockApiPost.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toContain('/workshop/bundles/bundle-1/components')
    expect(payload['component_type']).toBe('part')
    expect(payload['component_id']).toBe('prod-1')
    expect(payload['quantity']).toBe('2.000')
    expect(payload['unit_id']).toBe('unit-each')
    expect(onSaved).toHaveBeenCalled()
  })

  it('shows a validation error when quantity is missing', async () => {
    const user = userEvent.setup()
    renderWithProviders(
      <BundleComponentFormModal bundle={bundle} onClose={() => undefined} onSaved={() => undefined} />,
    )

    const quantityInput = screen.getByTestId('bundle-component-quantity') as HTMLInputElement
    await user.clear(quantityInput)

    await user.click(screen.getByRole('button', { name: /save component/i }))

    expect(screen.getByText(/Quantity is required/i)).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('surfaces the cycle-detection error on 422 BUNDLE_CYCLE response', async () => {
    mockApiGet.mockResolvedValue(listResponse([]))
    const cycleError = new AxiosError('cycle')
    cycleError.response = {
      status: 422,
      data: {
        error: { code: 'BUNDLE_CYCLE', message: 'cycle' },
      },
      statusText: 'Unprocessable Entity',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }
    mockApiPost.mockRejectedValueOnce(cycleError)

    const user = userEvent.setup()
    renderWithProviders(
      <BundleComponentFormModal bundle={bundle} onClose={() => undefined} onSaved={() => undefined} />,
    )

    // Switch to nested_bundle — the mocked BundlePicker auto-selects
    // `nested-bundle-1` on mount so we can actually drive submit through
    // to the API rejection and exercise the BUNDLE_CYCLE onError branch.
    const typeSelect = screen.getByTestId('bundle-component-type-select') as HTMLSelectElement
    await user.selectOptions(typeSelect, 'nested_bundle')

    // Wait for the stub's mount-effect to fire onChange with the nested bundle.
    await waitFor(() => {
      expect(screen.getByText(/nested: Nested bundle/i)).toBeInTheDocument()
    })

    // Seed a unit id so client-side unitRequired doesn't short-circuit.
    const unitSelect = screen.getByTestId('bundle-component-unit-select') as HTMLSelectElement
    await waitFor(() => {
      expect(unitSelect.options.length).toBeGreaterThan(1)
    })
    fireEvent.change(unitSelect, { target: { value: 'unit-each' } })

    await user.click(screen.getByRole('button', { name: /save component/i }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalled()
    })
    const [, payload] = mockApiPost.mock.calls[0] as [string, Record<string, unknown>]
    expect(payload['component_type']).toBe('nested_bundle')
    expect(payload['component_id']).toBe('nested-bundle-1')

    // The cycle translation ("This nested bundle would create a cycle.")
    // must surface at the nested-bundle field after the rejected POST.
    await waitFor(() => {
      expect(screen.getByText(/would create a cycle/i)).toBeInTheDocument()
    })
  })

  it('pre-selects the unit in edit mode and PATCHes with the resolved unit_id', async () => {
    mockApiGet.mockResolvedValue(listResponse([]))
    mockApiPatch.mockResolvedValue({
      data: {
        data: {
          id: 'comp-7',
          bundle_id: bundle.id,
          component_type: 'part',
          component_id: 'prod-9',
          component_display_name: 'Brake pad',
          quantity: '3.000',
          unit: 'EA',
          override_unit_price: null,
          is_optional: false,
          display_order: 0,
          notes: null,
        },
      },
    })

    const existing: ServiceBundleComponentData = {
      id: 'comp-7',
      bundle_id: bundle.id,
      component_type: 'part',
      component_id: 'prod-9',
      component_display_name: 'Brake pad',
      quantity: '3.000',
      quantity_decimals: 3,
      unit: 'EA',
      override_unit_price: null,
      is_optional: false,
      display_order: 0,
      notes: null,
    }

    const onSaved = vi.fn()
    const user = userEvent.setup()
    renderWithProviders(
      <BundleComponentFormModal
        bundle={bundle}
        component={existing}
        onClose={() => undefined}
        onSaved={onSaved}
      />,
    )

    // Edit mode gates on useUnits resolving. Wait past the loading
    // placeholder until the real unit <select> is in the DOM with its
    // pre-selected value.
    const unitSelect = (await screen.findByTestId(
      'bundle-component-unit-select',
    )) as HTMLSelectElement
    const quantityInput = screen.getByTestId('bundle-component-quantity')

    // Symbol "EA" in the DTO must resolve to the 'unit-each' id.
    expect(unitSelect.value).toBe('unit-each')
    expect(quantityInput).toHaveAttribute('step', '0.001')
    expect(quantityInput).toHaveAttribute('value', '3.000')

    await user.click(screen.getByRole('button', { name: /save component/i }))

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalled()
    })
    const [url, payload] = mockApiPatch.mock.calls[0] as [string, Record<string, unknown>]
    expect(url).toContain('/workshop/bundles/bundle-1/components/comp-7')
    expect(payload['unit_id']).toBe('unit-each')
    expect(payload['component_type']).toBe('part')
    expect(payload['component_id']).toBe('prod-9')
    expect(payload['quantity']).toBe('3.000')
    expect(onSaved).toHaveBeenCalled()
  })
})
