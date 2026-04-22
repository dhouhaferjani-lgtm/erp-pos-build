import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { WorkOrderDetailPage } from '../pages/WorkOrderDetailPage'
import type { WorkOrder } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (opts !== undefined && Object.keys(opts).length > 0) {
        return `${key}:${JSON.stringify(opts)}`
      }
      return key
    },
  }),
}))

interface UseWorkOrderResult {
  data: WorkOrder | undefined
  isLoading: boolean
  isError: boolean
  error: Error | null
}

const useWorkOrderMock = vi.fn<(id: string | undefined) => UseWorkOrderResult>()
vi.mock('../hooks/useWorkOrders', () => ({
  useWorkOrder: (id: string | undefined): UseWorkOrderResult => useWorkOrderMock(id),
  useTransitionWorkOrder: () => ({ mutate: vi.fn(), isPending: false }),
  useApproveWorkOrder: () => ({ mutate: vi.fn(), isPending: false }),
  useCancelWorkOrder: () => ({ mutate: vi.fn(), isPending: false }),
  useCompleteWorkOrder: () => ({ mutate: vi.fn(), isPending: false }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: () => true,
    hasAnyPermission: () => true,
    hasAllPermissions: () => true,
    canAccessModule: () => true,
    hasRole: () => true,
    isAdmin: () => true,
    roles: ['admin'],
  }),
}))

function makeWorkOrder(overrides: Partial<WorkOrder> = {}): WorkOrder {
  return {
    id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    tenant_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    company_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    location_id: null,
    work_order_number: 'WO-2026-000042',
    status: 'invoiced',
    type: 'repair',
    customer_partner_id: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    customer_display_name: 'Acme Motors',
    vehicle_id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    vehicle_display_name: 'Peugeot 208 ABC-123',
    opened_by_user_id: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
    primary_technician_profile_id: null,
    primary_technician_display_name: null,
    mileage_at_intake: 12345,
    customer_complaint: null,
    diagnosis: null,
    internal_notes: null,
    scheduled_start_at: null,
    scheduled_end_at: null,
    promised_at: null,
    started_at: null,
    paused_at: null,
    completed_at: null,
    cancelled_at: null,
    cancellation_reason: null,
    approval_captured_at: null,
    approval_method: null,
    approval_reference: null,
    currency: 'TND',
    estimated_totals: null,
    actual_totals: null,
    quote_document_id: null,
    invoice_document_id: null,
    lines: [],
    assignments: [],
    status_history: [],
    created_at: '2026-04-19T12:00:00Z',
    updated_at: '2026-04-20T08:00:00Z',
    ...overrides,
  }
}

function renderPage(workOrderId: string): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/workshop/work-orders/${workOrderId}`]}>
        <Routes>
          <Route
            path="/workshop/work-orders/:id"
            element={<WorkOrderDetailPage />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkOrderDetailPage — invoice back-link', () => {
  beforeEach(() => {
    useWorkOrderMock.mockReset()
  })

  it('renders a link to the invoice when invoice_document_id is set', () => {
    const invoiceId = '11111111-2222-3333-4444-555555555555'
    useWorkOrderMock.mockReturnValue({
      data: makeWorkOrder({ invoice_document_id: invoiceId }),
      isLoading: false,
      isError: false,
      error: null,
    })

    renderPage('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')

    const link = screen
      .getAllByRole('link')
      .find((el) => el.getAttribute('href') === `/sales/invoices/${invoiceId}`)
    expect(link).toBeDefined()
    expect(link?.textContent).toContain('11111111')
    expect(link?.textContent).toContain('detail.invoice_link')
  })

  it('does not render an invoice link when invoice_document_id is null', () => {
    useWorkOrderMock.mockReturnValue({
      data: makeWorkOrder({ invoice_document_id: null }),
      isLoading: false,
      isError: false,
      error: null,
    })

    renderPage('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')

    const invoiceLinks = screen
      .getAllByRole('link')
      .filter((el) => el.getAttribute('href')?.startsWith('/sales/invoices'))
    expect(invoiceLinks).toHaveLength(0)
  })
})
