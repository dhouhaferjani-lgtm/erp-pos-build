import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '@/lib/i18n'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { expenseApi } from '../../api/expenseApi'
import type { CreateExpenseDTO, LinkableInvoice, OperationResolution } from '../../types'
import { ExpenseFormFields } from './ExpenseFormFields'

vi.mock('../../../treasury/hooks/usePaymentMethods', () => ({
  useActivePaymentMethods: () => ({ data: [], isLoading: false, error: null }),
}))

vi.mock('../../../treasury/hooks/usePaymentRepositories', () => ({
  useActivePaymentRepositories: () => ({ data: [], isLoading: false, error: null }),
}))

vi.mock('../../../../hooks/useCurrency', () => ({
  getDecimals: () => 3,
  useCurrency: () => ({ currency: 'TND' }),
}))

vi.mock('../molecules/ExpenseCategorySelect', () => ({
  ExpenseCategorySelect: ({ value, onChange }: { value: string; onChange: (value: string) => void }) => (
    <select
      aria-label="Catégorie"
      value={value}
      onChange={(event) => { onChange(event.target.value) }}
    >
      <option value="">Aucune catégorie</option>
      <option value="expense-category-1">Frais transport</option>
    </select>
  ),
}))

vi.mock('../../api/expenseApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/expenseApi')>('../../api/expenseApi')
  return {
    ...actual,
    expenseApi: {
      ...actual.expenseApi,
      listLinkableInvoices: vi.fn(),
      resolveLinkableOperations: vi.fn(),
    },
  }
})

const supplierInvoice: LinkableInvoice = {
  id: 'supplier-invoice-1',
  document_number: 'SI-2026-0001',
  partner_name: 'Transport SARL',
  document_date: '2026-07-01',
  total: '1000.000',
  currency: 'TND',
  side: 'purchase',
}

const singlePurchaseOrderResolution: OperationResolution = {
  side: 'purchase',
  invoice: {
    id: 'supplier-invoice-1',
    document_number: 'SI-2026-0001',
    currency: 'TND',
  },
  operations: [
    {
      document_id: 'po-1',
      kind: 'purchase_order',
      number: 'PO-2026-0001',
      date: '2026-06-30',
      status: 'received',
      received_at: '2026-06-30',
      line_count: 1,
      total: '1000.000',
      currency: 'TND',
    },
  ],
  auto_selected_id: 'po-1',
}

function setTenantScope(tenantId = 'tenant-1', companyId = 'company-1') {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token-1',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })
}

function renderForm(onSubmit = vi.fn<(data: CreateExpenseDTO) => void>()) {
  const queryClient = createQueryClient()

  render(
    <QueryClientProvider client={queryClient}>
      <ExpenseFormFields onSave={onSubmit} />
    </QueryClientProvider>
  )

  return { onSubmit, queryClient, user: userEvent.setup() }
}

async function chooseLinkedCost() {
  await userEvent.click(screen.getByRole('radio', { name: 'Coût lié à une opération' }))
  return screen.findByRole('combobox', { name: 'Facture liée' })
}

async function chooseSupplierInvoice() {
  const invoiceSelect = await chooseLinkedCost()
  await screen.findByRole('option', { name: /SI-2026-0001 · Transport SARL/ })
  fireEvent.change(invoiceSelect, { target: { value: 'supplier-invoice-1' } })
}

beforeEach(async () => {
  await i18n.changeLanguage('fr')
  vi.mocked(expenseApi.listLinkableInvoices).mockReset()
  vi.mocked(expenseApi.resolveLinkableOperations).mockReset()
  vi.mocked(expenseApi.listLinkableInvoices).mockResolvedValue([supplierInvoice])
  vi.mocked(expenseApi.resolveLinkableOperations).mockResolvedValue(singlePurchaseOrderResolution)
  setTenantScope()
})

describe('ExpenseFormFields linked cost controls', () => {
  it('starts as a generic expense and keeps linked-cost controls hidden until toggled', async () => {
    const { onSubmit, user } = renderForm()

    expect(screen.getByRole('radio', { name: 'Dépense générale' })).toBeChecked()
    expect(screen.queryByRole('combobox', { name: 'Facture liée' })).not.toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: 'Coût lié à une opération' }))
    expect(await screen.findByRole('combobox', { name: 'Facture liée' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Type de coût' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Méthode de répartition' })).toBeInTheDocument()

    await user.click(screen.getByRole('radio', { name: 'Dépense générale' }))
    expect(screen.queryByRole('combobox', { name: 'Facture liée' })).not.toBeInTheDocument()

    fireEvent.change(screen.getByRole('spinbutton', { name: /Montant/ }), {
      target: { value: '42.500' },
    })
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => { expect(onSubmit).toHaveBeenCalledTimes(1) })
    expect(onSubmit.mock.calls[0]?.[0]).toEqual(
      expect.objectContaining({
        expense_kind: 'generic',
        total: '42.500',
      })
    )
    expect(onSubmit.mock.calls[0]?.[0]).not.toEqual(
      expect.objectContaining({
        linked_invoice_id: expect.any(String) as string,
        linked_operation_id: expect.any(String) as string,
      })
    )
  })

  it('fires the invoice picker search under the active tenant and company scope', async () => {
    const { queryClient } = renderForm()

    expect(expenseApi.listLinkableInvoices).not.toHaveBeenCalled()

    await chooseLinkedCost()

    await waitFor(() => { expect(expenseApi.listLinkableInvoices).toHaveBeenCalledTimes(1) })
    const linkableInvoicesQuery = queryClient
      .getQueryCache()
      .getAll()
      .find((query) => query.queryKey[0] === 'expenses' && query.queryKey[1] === 'linkable-invoices')

    expect(linkableInvoicesQuery?.queryKey).toEqual([
      'expenses',
      'linkable-invoices',
      'tenant-1',
      'company-1',
    ])
  })

  it('renders the single-operation auto-chip and submits the resolved purchase order explicitly', async () => {
    const { onSubmit, user } = renderForm()

    await chooseSupplierInvoice()

    expect(await screen.findByText('Lié à')).toBeInTheDocument()
    expect(screen.getByText('PO-2026-0001')).toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'Opération liée' })).not.toBeInTheDocument()

    fireEvent.change(screen.getByRole('combobox', { name: 'Type de coût' }), {
      target: { value: 'transport' },
    })
    fireEvent.change(screen.getByRole('combobox', { name: 'Méthode de répartition' }), {
      target: { value: 'by_quantity' },
    })
    fireEvent.change(screen.getByRole('spinbutton', { name: /Montant/ }), {
      target: { value: '100.000' },
    })

    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => { expect(onSubmit).toHaveBeenCalledTimes(1) })
    const payload = onSubmit.mock.calls[0]?.[0]
    expect(payload).toEqual(
      expect.objectContaining({
        document_date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/) as string,
        is_paid: false,
        total: '100.000',
        expense_kind: 'linked_cost',
        linked_invoice_id: 'supplier-invoice-1',
        linked_operation_id: 'po-1',
        cost_type: 'transport',
        split_method: 'by_quantity',
      })
    )
    expect(typeof payload?.total).toBe('string')
    expect(typeof payload?.linked_invoice_id).toBe('string')
    expect(typeof payload?.linked_operation_id).toBe('string')
  })

  it('downgrades a zero-operation linked invoice back to a generic expense before submit', async () => {
    vi.mocked(expenseApi.resolveLinkableOperations).mockResolvedValue({
      ...singlePurchaseOrderResolution,
      operations: [],
      auto_selected_id: null,
    })
    const { onSubmit, user } = renderForm()

    await chooseSupplierInvoice()
    await user.click(await screen.findByRole('button', { name: 'Enregistrer comme dépense générale' }))

    expect(screen.getByRole('radio', { name: 'Dépense générale' })).toBeChecked()
    expect(screen.queryByRole('combobox', { name: 'Facture liée' })).not.toBeInTheDocument()

    fireEvent.change(screen.getByRole('spinbutton', { name: /Montant/ }), {
      target: { value: '77.250' },
    })
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    await waitFor(() => { expect(onSubmit).toHaveBeenCalledTimes(1) })
    expect(onSubmit.mock.calls[0]?.[0]).toEqual(
      expect.objectContaining({
        expense_kind: 'generic',
        linked_invoice_id: undefined,
        linked_operation_id: undefined,
        total: '77.250',
      })
    )
  })

  it('resolves linked-cost French labels instead of rendering raw i18n keys', async () => {
    renderForm()

    await chooseLinkedCost()

    expect(screen.getByRole('radio', { name: 'Dépense générale' })).toBeInTheDocument()
    expect(screen.getByRole('radio', { name: 'Coût lié à une opération' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Facture liée' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeInTheDocument()
    expect(document.body).not.toHaveTextContent('expenses:form.kindLinked')
    expect(document.body).not.toHaveTextContent('expenses:form.linkedInvoice')
    expect(document.body).not.toHaveTextContent('common:save')
  })

  it.todo(
    'surfaces OPERATION_PARTIALLY_RECEIVED as a readable linked-cost error instead of a generic Axios 422 message'
  )

  it.todo(
    'clears selected linked invoice and operation values when a user switches from linked_cost back to generic'
  )

  it.todo(
    'offers a posted linked-cost reverse action with confirm, success feedback, and readable error feedback'
  )
})
