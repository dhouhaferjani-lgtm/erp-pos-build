import { fireEvent, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'

import { QuoteRequestCreatePage } from './QuoteRequestCreatePage'

const mutateAsync = vi.hoisted(() => vi.fn())
const navigate = vi.hoisted(() => vi.fn())
const toastError = vi.hoisted(() => vi.fn())

vi.mock('./api', () => ({
  useCreateQuoteRequestGroup: () => ({ mutateAsync, isPending: false }),
}))

vi.mock('@/components/molecules/line-items/ProductLineSelect', () => ({
  ProductLineSelect: ({
    value,
    onChange,
  }: {
    value: string
    onChange: (value: string) => void
  }) => (
    <select
      data-testid="line-product-select"
      value={value}
      onChange={(event) => { onChange(event.target.value) }}
    >
      <option value="" />
      <option value="product-1">Crème solaire SPF50</option>
    </select>
  ),
}))

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: ({
    value,
    onChange,
    testId,
  }: {
    value: string
    onChange: (value: { id: string; name: string; type: 'supplier' } | null) => void
    testId?: string
  }) => (
    <select
      data-testid={testId ?? 'supplier-picker'}
      value={value}
      onChange={(event) => {
        onChange(event.target.value === '' ? null : {
          id: event.target.value,
          name: event.target.value === 'supplier-1' ? 'LaboDerm' : 'BioSupply',
          type: 'supplier',
        })
      }}
    >
      <option value="" />
      <option value="supplier-1">LaboDerm</option>
      <option value="supplier-2">BioSupply</option>
    </select>
  ),
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => navigate,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      if (key === 'common:validation.maxLength') return `Must be at most ${String(params?.['max'])} characters`
      return key
    },
  }),
}))

vi.mock('sonner', () => ({
  toast: { error: toastError },
}))

beforeEach(() => {
  vi.clearAllMocks()
  mutateAsync.mockResolvedValue({
    group_id: 'group-1',
    siblings: [
      { id: 'rfq-1' },
      { id: 'rfq-2' },
    ],
  })
})

function getCreateButton(): HTMLButtonElement {
  return screen.getByRole('button', { name: 'purchases:quoteRequests.actions.create' })
}

describe('QuoteRequestCreatePage', () => {
  it('renders canonical footer actions for cancel and create', () => {
    renderWithProviders(<QuoteRequestCreatePage />)

    expect(screen.getByRole('link', { name: 'common:actions.back' })).toHaveAttribute('href', '/purchases/quote-requests')
    expect(screen.getByRole('button', { name: 'common:actions.cancel' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'purchases:quoteRequests.actions.create' })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'actions.openSaveMenu' })).not.toBeInTheDocument()
  })

  it('confirms before cancelling a dirty draft', () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderWithProviders(<QuoteRequestCreatePage />)

    fireEvent.change(screen.getByLabelText('purchases:quoteRequests.fields.notes'), {
      target: { value: 'Do not discard' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'common:actions.cancel' }))

    expect(confirmSpy).toHaveBeenCalledWith('confirmation.unsavedChangesBody')
    expect(navigate).not.toHaveBeenCalled()
    confirmSpy.mockRestore()
  })

  it('submits a multi-supplier fan-out payload with string quantity and money values', async () => {
    renderWithProviders(<QuoteRequestCreatePage />)

    const supplierPickers = screen.getAllByTestId('supplier-picker')
    fireEvent.change(supplierPickers[0], { target: { value: 'supplier-1' } })
    fireEvent.click(screen.getByTestId('add-supplier'))
    fireEvent.change(screen.getAllByTestId('supplier-picker')[1], { target: { value: 'supplier-2' } })

    expect(screen.queryByTestId('line-product-0')).not.toBeInTheDocument()
    fireEvent.change(screen.getByTestId('line-product-select'), { target: { value: 'product-1' } })
    fireEvent.change(screen.getByTestId('line-description-0'), { target: { value: 'Crème solaire SPF50' } })
    fireEvent.change(screen.getByTestId('line-quantity-0'), { target: { value: '50.0000' } })
    fireEvent.change(screen.getByTestId('line-unit-price-0'), { target: { value: '8.200' } })
    fireEvent.click(getCreateButton())

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        partner_ids: ['supplier-1', 'supplier-2'],
        lines: [
          {
            product_id: 'product-1',
            variant_id: null,
            description: 'Crème solaire SPF50',
            quantity: '50.0000',
            unit_price: '8.200',
          },
        ],
        validity_date: null,
        notes: null,
      })
    })
    expect(navigate).toHaveBeenCalledWith('/purchases/quote-requests/groups/group-1')
  })

  it('blocks submit and renders an inline error when notes exceed the field limit', async () => {
    renderWithProviders(<QuoteRequestCreatePage />)

    fireEvent.change(screen.getByTestId('supplier-picker'), { target: { value: 'supplier-1' } })
    fireEvent.change(screen.getByTestId('line-product-select'), { target: { value: 'product-1' } })
    fireEvent.change(screen.getByLabelText('purchases:quoteRequests.fields.notes'), {
      target: { value: 'x'.repeat(2001) },
    })
    fireEvent.click(getCreateButton())

    expect(await screen.findByText('Must be at most 2000 characters')).toBeInTheDocument()
    expect(mutateAsync).not.toHaveBeenCalled()
  })

  it('routes a single-supplier fan-out to the detail page', async () => {
    mutateAsync.mockResolvedValue({ group_id: 'group-1', siblings: [{ id: 'rfq-1' }] })
    renderWithProviders(<QuoteRequestCreatePage />)

    fireEvent.change(screen.getByTestId('supplier-picker'), { target: { value: 'supplier-1' } })
    fireEvent.change(screen.getByTestId('line-product-select'), { target: { value: 'product-1' } })
    fireEvent.change(screen.getByTestId('line-quantity-0'), { target: { value: '1.0000' } })
    fireEvent.click(getCreateButton())

    await waitFor(() => {
      expect(navigate).toHaveBeenCalledWith('/purchases/quote-requests/rfq-1')
    })
  })

  it('surfaces create API errors with a toast', async () => {
    mutateAsync.mockRejectedValueOnce({
      response: { data: { error: { message: 'partner_ids.1 has already been taken' } } },
    })
    renderWithProviders(<QuoteRequestCreatePage />)

    fireEvent.change(screen.getByTestId('supplier-picker'), { target: { value: 'supplier-1' } })
    fireEvent.change(screen.getByTestId('line-product-select'), { target: { value: 'product-1' } })
    fireEvent.click(getCreateButton())

    await waitFor(() => {
      expect(toastError).toHaveBeenCalledWith('partner_ids.1 has already been taken')
    })
  })
})
