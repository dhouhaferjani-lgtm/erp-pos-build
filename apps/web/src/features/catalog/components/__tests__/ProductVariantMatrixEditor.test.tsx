import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ProductVariantMatrixEditor } from '../ProductVariantMatrixEditor'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => {
      if (second && typeof second === 'object') {
        return `${key} ${JSON.stringify(second)}`
      }
      return typeof second === 'string' ? second : key
    },
  }),
}))

vi.mock('@/contexts', () => ({
  useCompanyConfig: () => ({
    config: { vertical: 'fnb', currency: 'TND' },
    hasModule: () => false,
  }),
}))

let mockHasPermission = vi.fn().mockReturnValue(true)
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

interface UpdateVariantArg {
  variantId: string
  payload: {
    sku?: string
    barcode?: string | null
    price_override?: string | null
    cost_override?: string | null
    image_url?: string | null
    is_active?: boolean
  }
}

const mockGenerateMutate = vi.fn()
const mockUpdateMutate = vi.fn<(arg: UpdateVariantArg) => Promise<unknown>>()
const mockDeleteMutate = vi.fn()
let mockAttributes: unknown[] = []
let mockVariants: unknown[] = []
let mockAttributeValues: Record<string, unknown[]> = {}

vi.mock('../../hooks/useVariants', () => ({
  useAttributes: () => ({ data: mockAttributes, isLoading: false }),
  useAttributeValues: (attributeId: string) => ({
    data: mockAttributeValues[attributeId] ?? [],
    isLoading: false,
  }),
  useVariantsForProduct: () => ({ data: mockVariants, isLoading: false, isError: false }),
  useGenerateMatrix: () => ({ mutateAsync: mockGenerateMutate, isPending: false }),
  useUpdateVariant: () => ({ mutateAsync: mockUpdateMutate, isPending: false }),
  useDeleteVariant: () => ({ mutateAsync: mockDeleteMutate, isPending: false }),
}))

// VariantLabelDialog pulls in label formats + the label API; stub them so the
// editor renders the dialog without hitting the network.
vi.mock('../../hooks/useLabels', () => ({
  useLabelFormats: () => ({
    data: [
      {
        key: 'avery-l7160',
        label: 'Avery L7160',
        label_width_mm: 63.5,
        label_height_mm: 38.1,
        rows: 7,
        cols: 3,
      },
    ],
  }),
}))
vi.mock('../../api/labelApi', () => ({
  prepareVariantLabels: vi.fn(),
  downloadVariantLabelsPdf: vi.fn(),
}))

function variant(over: Record<string, unknown>) {
  return {
    id: 'v1',
    tenant_id: 't1',
    company_id: 'c1',
    product_id: 'p1',
    variant_code: 'RED-S',
    sku: 'SKU-RED-S',
    barcode: null,
    name_suffix: 'Red / S',
    is_default: false,
    is_active: true,
    display_order: 0,
    price_override: null,
    cost_override: null,
    image_url: null,
    attribute_values: [
      { attribute_id: 'a1', attribute_value_id: 'red' },
      { attribute_id: 'a2', attribute_value_id: 's' },
    ],
    ...over,
  }
}

function attrValue(id: string, label: string, over: Record<string, unknown> = {}) {
  return { id, code: id, label, hex_color: null, image_url: null, display_order: 0, ...over }
}

// The value-chip aria-label is i18n'd via t('catalog:variants.valueLabel', { label }).
// Under the test's `t` mock (key + JSON-stringified options), it renders as below.
function valueLabel(label: string) {
  return `catalog:variants.valueLabel {"label":"${label}"}`
}

describe('ProductVariantMatrixEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockHasPermission = vi.fn().mockReturnValue(true)
    mockAttributes = [
      { id: 'a1', tenant_id: 't1', code: 'color', name: 'Color', data_type: 'color', is_variant_axis: true, display_order: 0, is_active: true },
    ]
    mockVariants = []
    mockAttributeValues = {
      a1: [attrValue('red', 'Red'), attrValue('blue', 'Blue')],
    }
  })

  it('prompts to select axes when there are no variants', () => {
    render(<ProductVariantMatrixEditor productId="p1" />)
    expect(screen.getByText('catalog:variants.noVariants')).toBeInTheDocument()
  })

  it('hides the print-labels button when there are no variants', () => {
    render(<ProductVariantMatrixEditor productId="p1" />)
    expect(
      screen.queryByRole('button', { name: 'catalog:labels.printLabels' }),
    ).not.toBeInTheDocument()
  })

  it('opens the label dialog seeded with the product variants when print-labels is clicked', async () => {
    const user = userEvent.setup()
    mockVariants = [
      variant({ id: 'v1', name_suffix: 'Red / S' }),
      variant({ id: 'v2', name_suffix: 'Blue / M' }),
    ]
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(
      screen.getByRole('button', { name: 'catalog:labels.printLabels' }),
    )

    // Dialog is open (its format select is present) and seeded with both
    // variants — assert via the dialog's per-variant quantity inputs, whose
    // aria-labels are unique to the dialog.
    expect(
      await screen.findByRole('option', { name: 'Avery L7160' }),
    ).toBeInTheDocument()
    expect(
      screen.getByLabelText('catalog:labels.quantityFor {"name":"Red / S"}'),
    ).toBeInTheDocument()
    expect(
      screen.getByLabelText('catalog:labels.quantityFor {"name":"Blue / M"}'),
    ).toBeInTheDocument()
  })

  it('hides the print-labels button without the catalog.labels.print permission', () => {
    mockVariants = [variant({ id: 'v1', name_suffix: 'Red / S' })]
    mockHasPermission = vi.fn((p: string) => p !== 'catalog.labels.print')
    render(<ProductVariantMatrixEditor productId="p1" />)
    expect(
      screen.queryByRole('button', { name: 'catalog:labels.printLabels' }),
    ).not.toBeInTheDocument()
  })

  it('reveals value chips when an axis is checked and counts the combinations', async () => {
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    // No value chips shown until the axis is checked.
    expect(screen.queryByLabelText(valueLabel('Red'))).not.toBeInTheDocument()

    await user.click(screen.getByLabelText('Color'))

    // Chips revealed; both values auto-selected → combo count = 2.
    expect(await screen.findByLabelText(valueLabel('Red'))).toBeInTheDocument()
    expect(screen.getByLabelText(valueLabel('Blue'))).toBeInTheDocument()
    await waitFor(() => {
      expect(
        screen.getByText('catalog:variants.comboCount {"count":2}'),
      ).toBeInTheDocument()
    })
  })

  it('generates a matrix posting the selected axes and value ids', async () => {
    mockGenerateMutate.mockResolvedValue({
      data: [],
      meta: { created_count: 2, skipped_count: 0, restored_count: 0 },
    })
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByLabelText('Color'))
    await screen.findByLabelText(valueLabel('Red'))
    await user.click(screen.getByRole('button', { name: 'catalog:variants.generate' }))

    await waitFor(() => {
      expect(mockGenerateMutate).toHaveBeenCalledWith([
        { attribute_id: 'a1', value_ids: ['red', 'blue'] },
      ])
    })
  })

  it('applies a soft-warn style and message when the combo count is large', async () => {
    // 60 values → 60 combinations, over the soft-warn threshold of 50.
    mockAttributeValues = {
      a1: Array.from({ length: 60 }, (_, i) => attrValue(`v${i}`, `Value ${i}`)),
    }
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByLabelText('Color'))

    const countEl = await screen.findByText('catalog:variants.comboCount {"count":60}')
    await waitFor(() => {
      expect(countEl.className).toContain(colorTokens.intent.warning.textStrong)
    })
  })

  it('disables Generate with a message when the combo count exceeds the hard cap', async () => {
    // 201 values → 201 combinations, over MAX_VARIANTS_PER_GENERATE of 200.
    mockAttributeValues = {
      a1: Array.from({ length: 201 }, (_, i) => attrValue(`v${i}`, `Value ${i}`)),
    }
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByLabelText('Color'))
    await screen.findByLabelText(valueLabel('Value 0'))

    await waitFor(() => {
      expect(
        screen.getByRole('button', { name: 'catalog:variants.generate' }),
      ).toBeDisabled()
    })
    expect(
      screen.getByText('catalog:variants.tooMany {"max":200}'),
    ).toBeInTheDocument()
  })

  it('renders an editable row per generated variant', () => {
    mockVariants = [
      variant({ id: 'v1', sku: 'SKU-RED-S' }),
      variant({ id: 'v2', sku: 'SKU-RED-M', name_suffix: 'Red / M' }),
    ]
    render(<ProductVariantMatrixEditor productId="p1" />)
    expect(screen.getByDisplayValue('SKU-RED-S')).toBeInTheDocument()
    expect(screen.getByDisplayValue('SKU-RED-M')).toBeInTheDocument()
  })

  it('hydrates the axis/value selection from existing variants', async () => {
    mockAttributeValues = {
      a1: [attrValue('red', 'Red'), attrValue('blue', 'Blue')],
    }
    mockVariants = [
      variant({ id: 'v1', attribute_values: [{ attribute_id: 'a1', attribute_value_id: 'red' }] }),
    ]
    render(<ProductVariantMatrixEditor productId="p1" />)

    // Axis pre-checked from existing variants → chips visible, red selected.
    const redChip = await screen.findByLabelText(valueLabel('Red'))
    expect(redChip).toBeChecked()
    const blueChip = screen.getByLabelText(valueLabel('Blue'))
    expect(blueChip).not.toBeChecked()
  })

  it('shows an orphan badge when a variant falls outside the current selection', async () => {
    mockAttributeValues = {
      a1: [attrValue('red', 'Red'), attrValue('blue', 'Blue')],
    }
    // Hydration seeds {a1: [red, blue]} from the two existing variants. Once the
    // user unchecks "Blue", the blue variant (v2) is no longer in the selection
    // and must be flagged as an orphan.
    mockVariants = [
      variant({ id: 'v1', name_suffix: 'Red', attribute_values: [{ attribute_id: 'a1', attribute_value_id: 'red' }] }),
      variant({ id: 'v2', name_suffix: 'Blue', attribute_values: [{ attribute_id: 'a1', attribute_value_id: 'blue' }] }),
    ]
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    // No orphans while the full selection is present.
    expect(screen.queryByText('catalog:variants.orphan')).not.toBeInTheDocument()

    const blueChip = await screen.findByLabelText(valueLabel('Blue'))
    await user.click(blueChip)

    await waitFor(() => {
      expect(screen.getByText('catalog:variants.orphan')).toBeInTheDocument()
    })
  })

  it('does NOT auto-refill an axis after the user deselects all of its values', async () => {
    // Regression: seedAxisValues must seed exactly once per axis. After the user
    // empties an axis (length → 0), a subsequent parent render must NOT treat it
    // as "not yet seeded" and refill every value.
    mockAttributeValues = {
      a1: [attrValue('red', 'Red'), attrValue('blue', 'Blue')],
    }
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByLabelText('Color'))

    const redChip = await screen.findByLabelText(valueLabel('Red'))
    const blueChip = screen.getByLabelText(valueLabel('Blue'))

    // Both auto-seeded → 2 combinations.
    expect(redChip).toBeChecked()
    expect(blueChip).toBeChecked()
    await waitFor(() => {
      expect(
        screen.getByText('catalog:variants.comboCount {"count":2}'),
      ).toBeInTheDocument()
    })

    // Deselect ALL values of the axis.
    await user.click(redChip)
    await user.click(blueChip)

    // The axis stays empty: neither value is refilled and the combo count is 0.
    expect(redChip).not.toBeChecked()
    expect(blueChip).not.toBeChecked()
    await waitFor(() => {
      expect(
        screen.getByText('catalog:variants.comboCount {"count":0}'),
      ).toBeInTheDocument()
    })
    // Give any stray seed effect a chance to (wrongly) re-fire, then re-assert.
    await waitFor(() => {
      expect(redChip).not.toBeChecked()
      expect(blueChip).not.toBeChecked()
    })
  })

  it('saves an edited SKU for a variant', async () => {
    mockVariants = [variant({ id: 'v1', sku: 'SKU-RED-S' })]
    mockUpdateMutate.mockResolvedValue(variant({ id: 'v1' }))
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    const skuInput = screen.getByDisplayValue('SKU-RED-S')
    await user.clear(skuInput)
    await user.type(skuInput, 'SKU-NEW')

    await user.click(screen.getByRole('button', { name: 'catalog:variants.save' }))

    await waitFor(() => {
      expect(mockUpdateMutate).toHaveBeenCalled()
    })
    const arg = mockUpdateMutate.mock.calls[0][0]
    expect(arg.variantId).toBe('v1')
    expect(arg.payload.sku).toBe('SKU-NEW')
  })

  it('toggles is_active per variant on save', async () => {
    mockVariants = [variant({ id: 'v1', is_active: true })]
    mockUpdateMutate.mockResolvedValue(variant({ id: 'v1' }))
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByLabelText('catalog:variants.active'))
    await user.click(screen.getByRole('button', { name: 'catalog:variants.save' }))

    await waitFor(() => {
      expect(mockUpdateMutate).toHaveBeenCalled()
    })
    const arg = mockUpdateMutate.mock.calls[0][0]
    expect(arg.variantId).toBe('v1')
    expect(arg.payload.is_active).toBe(false)
  })

  it('renders the price override as a precision-safe number (MoneyInput) input', () => {
    mockVariants = [variant({ id: 'v1', price_override: '12.500' })]
    render(<ProductVariantMatrixEditor productId="p1" />)
    const priceInput = screen.getByLabelText('catalog:variants.price Red / S')
    expect(priceInput).toHaveAttribute('type', 'number')
  })

  it('saves an edited price as a canonical string (no float coercion)', async () => {
    mockVariants = [variant({ id: 'v1', price_override: '' })]
    mockUpdateMutate.mockResolvedValue(variant({ id: 'v1' }))
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    const priceInput = screen.getByLabelText('catalog:variants.price Red / S')
    await user.type(priceInput, '12.5')
    await user.click(screen.getByRole('button', { name: 'catalog:variants.save' }))

    await waitFor(() => {
      expect(mockUpdateMutate).toHaveBeenCalled()
    })
    const arg = mockUpdateMutate.mock.calls[0][0]
    expect(arg.payload.price_override).toBe('12.5')
  })

  it('shows an inline barcode error when save returns a 422', async () => {
    mockVariants = [variant({ id: 'v1', name_suffix: 'Red / S' })]
    mockUpdateMutate.mockRejectedValue({
      response: {
        status: 422,
        data: { message: 'invalid', errors: { barcode: ['Barcode already in use.'] } },
      },
    })
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(screen.getByRole('button', { name: 'catalog:variants.save' }))

    expect(await screen.findByText('Barcode already in use.')).toBeInTheDocument()
  })

  it('opens a confirm dialog before deleting, and cancel does not delete', async () => {
    mockVariants = [variant({ id: 'v1', name_suffix: 'Red / S' })]
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(
      screen.getByRole('button', { name: 'catalog:variants.delete Red / S' }),
    )
    expect(screen.getByText('catalog:variants.deleteTitle')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'actions.cancel' }))

    await waitFor(() => {
      expect(screen.queryByText('catalog:variants.deleteTitle')).not.toBeInTheDocument()
    })
    expect(mockDeleteMutate).not.toHaveBeenCalled()
  })

  it('confirms delete → calls deleteVariant', async () => {
    mockVariants = [variant({ id: 'v1', name_suffix: 'Red / S' })]
    mockDeleteMutate.mockResolvedValue(undefined)
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(
      screen.getByRole('button', { name: 'catalog:variants.delete Red / S' }),
    )
    await user.click(screen.getByRole('button', { name: 'catalog:variants.delete' }))

    await waitFor(() => {
      expect(mockDeleteMutate).toHaveBeenCalledWith('v1')
    })
  })

  it('on a 422 (has stock) the dialog offers deactivate instead', async () => {
    mockVariants = [variant({ id: 'v1', name_suffix: 'Red / S' })]
    mockDeleteMutate.mockRejectedValue({ response: { status: 422 } })
    mockUpdateMutate.mockResolvedValue(variant({ id: 'v1', is_active: false }))
    const user = userEvent.setup()
    render(<ProductVariantMatrixEditor productId="p1" />)

    await user.click(
      screen.getByRole('button', { name: 'catalog:variants.delete Red / S' }),
    )
    await user.click(screen.getByRole('button', { name: 'catalog:variants.delete' }))

    // Dialog flips to deactivate body after the 422.
    expect(
      await screen.findByText('catalog:variants.deactivateTitle'),
    ).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'catalog:variants.deactivate' }))

    await waitFor(() => {
      expect(mockUpdateMutate).toHaveBeenCalledWith({
        variantId: 'v1',
        payload: { is_active: false },
      })
    })
  })
})
