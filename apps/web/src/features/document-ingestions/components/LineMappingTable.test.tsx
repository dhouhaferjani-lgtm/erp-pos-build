import { useState } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { LineMappingTable, type ReviewedLineState } from './LineMappingTable'
import type { ExtractedField, ExtractedLine, ProductCandidate } from '../types'

// `api.get` (axios-shaped response) backs ProductPicker's catalog search;
// `apiGet` (bare-data) backs AddQuickProductModal's TaxConfigurationSelect
// (`taxConfigurationApi.list`). They are mocked separately because the two
// client helpers unwrap differently — collapsing them into one mock makes
// one of the two callers see the wrong shape.
const mockApiClientGet = vi.hoisted(() => vi.fn<(url: string) => unknown>())
const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiClientGet },
    apiGet: mockApiGet,
    apiPost: mockApiPost,
  }
})

function field(value: string): ExtractedField {
  return { value, confidence: 0.9, sourceBbox: null }
}

const doliprane: ExtractedLine = {
  description: field('Doliprane 1g'),
  supplierRef: field('SKU-DOLI'),
  quantity: field('2.0000'),
  unitPrice: field('4.850'),
  taxRate: field('7'),
  lineTotal: field('9.700'),
  batchNumber: null,
  expiryDate: null,
}

const dolipraneCandidate: ProductCandidate = {
  id: 'cand-1',
  name: 'Doliprane Comprime',
  sku: 'DOLI-1',
  tax_rate: '7.00',
}

function defaultValue(): ReviewedLineState {
  return {
    productId: '',
    quantity: '2.0000',
    unitPrice: '4.850',
    vatRate: '',
    freeQuantity: '0',
    batchNumber: '',
    expiryDate: '',
    sourceLineId: '',
  }
}

function productListResponse<T>(data: T[]) {
  return {
    data: {
      data,
      meta: { total: data.length, current_page: 1, per_page: 20, last_page: 1 },
    },
  }
}

interface HarnessProps {
  lines?: ExtractedLine[]
  productCandidates?: ProductCandidate[][]
  initialValues?: ReviewedLineState[]
  onChangeSpy?: (index: number, value: ReviewedLineState) => void
  kind?: string
}

function Harness({
  lines = [doliprane],
  productCandidates = [[dolipraneCandidate]],
  initialValues,
  onChangeSpy,
  kind = 'supplier_delivery_note',
}: HarnessProps) {
  const pristine = initialValues ?? [defaultValue()]
  const [values, setValues] = useState<ReviewedLineState[]>(pristine)
  return (
    <LineMappingTable
      kind={kind}
      currency="TND"
      lines={lines}
      productCandidates={productCandidates}
      receiptLineCandidates={[]}
      values={values}
      initialValues={pristine}
      onChange={(index, value) => {
        onChangeSpy?.(index, value)
        setValues((prev) => prev.map((existing, i) => (i === index ? value : existing)))
      }}
    />
  )
}

function setTenant() {
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
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

describe('LineMappingTable product mapping', () => {
  beforeEach(() => {
    mockApiClientGet.mockReset()
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiClientGet.mockResolvedValue(productListResponse([]))
    mockApiGet.mockResolvedValue([])
    window.localStorage.setItem('autoerp-language', 'en')
    setTenant()
  })

  afterEach(() => {
    resetTenant()
  })

  it('renders candidate chips and selecting one sets onChange with the candidate id and autofilled vatRate', async () => {
    const user = userEvent.setup()
    const onChangeSpy = vi.fn()
    renderWithProviders(<Harness onChangeSpy={onChangeSpy} />)

    const line = screen.getByTestId('review-line-0')
    const chip = within(line).getByRole('button', { name: 'Doliprane Comprime' })
    await user.click(chip)

    expect(onChangeSpy).toHaveBeenCalledWith(0, expect.objectContaining({
      productId: 'cand-1',
      vatRate: '7.00',
    }))
  })

  it('searches the catalog via the product picker and selecting a result sets onChange with its id', async () => {
    const brakePad = {
      id: 'prod-99',
      sku: 'BRAKE-PAD',
      name: 'Plaquettes de frein',
      sale_price: '95.000',
      currency: 'TND',
    }
    mockApiClientGet.mockResolvedValue(productListResponse([brakePad]))
    const user = userEvent.setup()
    const onChangeSpy = vi.fn()
    renderWithProviders(<Harness onChangeSpy={onChangeSpy} />)

    const picker = screen.getByTestId('line-product-picker-0')
    const combo = within(picker).getByRole('combobox')
    await user.click(combo)
    await user.type(combo, 'plaq')

    await waitFor(() => {
      const urls = mockApiClientGet.mock.calls.map((c) => c[0])
      expect(urls.some((u) => u.includes('search=plaq'))).toBe(true)
    })

    const option = await screen.findByRole('option', { name: /BRAKE-PAD.*Plaquettes de frein/ })
    await user.click(option)

    expect(onChangeSpy).toHaveBeenCalledWith(0, expect.objectContaining({ productId: 'prod-99' }))
  })

  it('opens the create-product modal from a line seeded with the line description and unit price', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Harness />)

    const line = screen.getByTestId('review-line-0')
    await user.click(within(line).getByRole('button', { name: '＋ New product' }))

    expect(await screen.findByLabelText(/^name/i)).toHaveValue('Doliprane 1g')
    expect(screen.getByLabelText(/sale price/i)).toHaveValue(4.85)
  })

  it('registers the created product into the line and shows it in the picker after AddQuickProductModal onSuccess', async () => {
    const user = userEvent.setup()
    mockApiPost.mockResolvedValueOnce({
      id: 'p-9',
      name: 'Doliprane',
      sku: null,
      is_physical: true,
      sale_price: 4.85,
      cost_price: 0,
      tax_rate: 7,
    })
    const onChangeSpy = vi.fn()
    renderWithProviders(<Harness onChangeSpy={onChangeSpy} />)

    const line = screen.getByTestId('review-line-0')
    await user.click(within(line).getByRole('button', { name: '＋ New product' }))

    await screen.findByLabelText(/^name/i)
    await user.click(screen.getByRole('button', { name: 'Create' }))

    await waitFor(() => {
      expect(onChangeSpy).toHaveBeenCalledWith(0, expect.objectContaining({ productId: 'p-9' }))
    })
    expect(await screen.findByText('Doliprane')).toBeInTheDocument()
  })
})

describe('LineMappingTable validation cues', () => {
  beforeEach(() => {
    mockApiClientGet.mockReset()
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiClientGet.mockResolvedValue(productListResponse([]))
    mockApiGet.mockResolvedValue([])
    window.localStorage.setItem('autoerp-language', 'en')
    setTenant()
  })

  afterEach(() => {
    resetTenant()
  })

  it('shows the machine cue on every editable field of a pristine line', () => {
    renderWithProviders(<Harness initialValues={[defaultValue()]} />)

    const line = screen.getByTestId('review-line-0')
    const machineCues = within(line).getAllByLabelText('Read from document')
    // quantity + unitPrice (vatRate is not rendered for a delivery note)
    expect(machineCues).toHaveLength(2)
    expect(within(line).queryByLabelText('Edited by you')).not.toBeInTheDocument()
  })

  it('flips only the edited field to the human cue after a userEvent edit', async () => {
    const user = userEvent.setup()
    renderWithProviders(<Harness initialValues={[defaultValue()]} />)

    const line = screen.getByTestId('review-line-0')
    const quantityInput = within(line).getByRole('spinbutton', { name: 'Quantity' })
    await user.clear(quantityInput)
    await user.type(quantityInput, '9')

    await waitFor(() => {
      expect(within(line).getByLabelText('Edited by you')).toBeInTheDocument()
    })
    // unitPrice was never touched — it keeps the machine cue.
    expect(within(line).getByLabelText('Read from document')).toBeInTheDocument()
  })
})
