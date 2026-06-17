import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { WorkOrderDetailPage } from './WorkOrderDetailPage'
import type { WorkOrder, WorkOrderAssignment } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts !== undefined && Object.keys(opts).length > 0
        ? `${key}:${JSON.stringify(opts)}`
        : key,
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

function makeAssignment(overrides: Partial<WorkOrderAssignment> = {}): WorkOrderAssignment {
  return {
    id: '99999999-9999-4999-8999-999999999999',
    work_order_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    technician_profile_id: '88888888-8888-4888-8888-888888888888',
    technician_display_name: 'Sam Tech',
    is_lead: true,
    assigned_at: '2026-04-19T12:00:00Z',
    unassigned_at: null,
    notes: null,
    ...overrides,
  }
}

function makeWorkOrder(overrides: Partial<WorkOrder> = {}): WorkOrder {
  return {
    id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    tenant_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    company_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    location_id: null,
    work_order_number: 'WO-2026-000042',
    status: 'in_progress',
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

function renderPage(): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/workshop/work-orders/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']}>
        <Routes>
          <Route path="/workshop/work-orders/:id" element={<WorkOrderDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkOrderDetailPage (canonical shell)', () => {
  beforeEach(() => {
    useWorkOrderMock.mockReset()
  })

  it('renders the work order number as the page h1 via PageHeader', () => {
    useWorkOrderMock.mockReturnValue({
      data: makeWorkOrder(),
      isLoading: false,
      isError: false,
      error: null,
    })
    renderPage()

    expect(
      screen.getByRole('heading', { level: 1, name: 'WO-2026-000042' }),
    ).toBeInTheDocument()
  })

  it('renders a lead-technician badge via StatusBadge', () => {
    useWorkOrderMock.mockReturnValue({
      data: makeWorkOrder({ assignments: [makeAssignment({ is_lead: true })] }),
      isLoading: false,
      isError: false,
      error: null,
    })
    renderPage()

    expect(screen.getByText('detail.lead')).toBeInTheDocument()
  })

  it('renders an alert role when the work order fails to load', () => {
    useWorkOrderMock.mockReturnValue({
      data: undefined,
      isLoading: false,
      isError: true,
      error: new Error('nope'),
    })
    renderPage()

    expect(screen.getByRole('alert')).toHaveTextContent('detail.notFound')
  })
})
