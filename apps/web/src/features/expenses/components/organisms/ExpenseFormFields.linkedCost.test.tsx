import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { expenseApi } from '../../api/expenseApi'
import { ExpenseFormFields } from './ExpenseFormFields'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

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
      aria-label="expense category"
      value={value}
      onChange={(event) => onChange(event.target.value)}
    >
      <option value="">none</option>
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

describe('ExpenseFormFields linked cost controls', () => {
  beforeEach(() => {
    vi.mocked(expenseApi.listLinkableInvoices).mockResolvedValue([
      {
        id: 'supplier-invoice-1',
        document_number: 'SI-2026-0001',
        partner_name: 'Transport SARL',
        document_date: '2026-07-01',
        total: '1000.000',
        currency: 'TND',
        side: 'purchase',
      },
    ])
    vi.mocked(expenseApi.resolveLinkableOperations).mockResolvedValue({
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
    })
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'User',
        email: 'user@example.test',
        tenant_id: 'tenant-1',
        roles: [],
        email_verified_at: null,
      },
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1' })
  })

  it('submits linked purchase cost classification with resolved operation and split method', async () => {
    const onSubmit = vi.fn()
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })

    render(
      <QueryClientProvider client={queryClient}>
        <ExpenseFormFields onSubmit={onSubmit} />
      </QueryClientProvider>
    )

    fireEvent.click(screen.getByLabelText('expenses:form.kindLinked'))

    const invoiceSelect = await screen.findByLabelText('expenses:form.linkedInvoice')
    await screen.findByText(/SI-2026-0001/)
    fireEvent.change(invoiceSelect, { target: { value: 'supplier-invoice-1' } })

    expect(await screen.findByText(/PO-2026-0001/)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('expenses:form.costType'), {
      target: { value: 'transport' },
    })
    fireEvent.change(screen.getByLabelText('expenses:form.splitMethod'), {
      target: { value: 'by_quantity' },
    })
    fireEvent.change(screen.getByLabelText(/expenses:form.amount/), {
      target: { value: '100.000' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit.mock.calls[0]?.[0]).toEqual(
      expect.objectContaining({
        total: '100.000',
        expense_kind: 'linked_cost',
        linked_invoice_id: 'supplier-invoice-1',
        linked_operation_id: 'po-1',
        cost_type: 'transport',
        split_method: 'by_quantity',
      })
    )
  })
})
