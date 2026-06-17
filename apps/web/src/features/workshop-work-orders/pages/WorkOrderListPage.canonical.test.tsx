import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { WorkOrderListPage } from './WorkOrderListPage'
import type { WorkOrder } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) =>
      opts !== undefined && Object.keys(opts).length > 0
        ? `${key}:${JSON.stringify(opts)}`
        : key,
  }),
}))

interface UseWorkOrdersResult {
  data: { data: WorkOrder[]; meta: { total: number } } | undefined
  isLoading: boolean
  isError: boolean
  error: Error | null
}

const useWorkOrdersMock = vi.fn<() => UseWorkOrdersResult>()
vi.mock('../hooks/useWorkOrders', () => ({
  useWorkOrders: (): UseWorkOrdersResult => useWorkOrdersMock(),
}))

// WorkOrderRow pulls i18n + Link internals; stub it to keep this test focused
// on the page shell (header / filter / states).
vi.mock('../components/WorkOrderRow', () => ({
  WorkOrderRow: ({ workOrder }: { workOrder: WorkOrder }) => (
    <div data-testid="wo-row">{workOrder.work_order_number}</div>
  ),
}))

function makeWorkOrder(): WorkOrder {
  return {
    id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    tenant_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
    company_id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    location_id: null,
    work_order_number: 'WO-2026-000001',
    status: 'received',
    type: 'repair',
    customer_partner_id: 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
    customer_display_name: 'Acme Motors',
    vehicle_id: 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
    vehicle_display_name: 'Peugeot 208',
    opened_by_user_id: 'ffffffff-ffff-4fff-8fff-ffffffffffff',
    primary_technician_profile_id: null,
    primary_technician_display_name: null,
    mileage_at_intake: 1000,
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
  }
}

function renderPage(): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WorkOrderListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('WorkOrderListPage (canonical shell)', () => {
  beforeEach(() => {
    useWorkOrdersMock.mockReset()
  })

  it('renders the title as the single page h1 and a "new" action link', () => {
    useWorkOrdersMock.mockReturnValue({
      data: { data: [], meta: { total: 0 } },
      isLoading: false,
      isError: false,
      error: null,
    })
    renderPage()

    expect(
      screen.getByRole('heading', { level: 1, name: 'list.title' }),
    ).toBeInTheDocument()

    const newLink = screen
      .getAllByRole('link')
      .find((el) => el.getAttribute('href') === '/workshop/work-orders/new')
    expect(newLink).toBeDefined()
    expect(newLink?.textContent).toContain('actions.newWorkOrder')
  })

  it('renders the status filter as a labelled <select> via the Select atom', () => {
    useWorkOrdersMock.mockReturnValue({
      data: { data: [], meta: { total: 0 } },
      isLoading: false,
      isError: false,
      error: null,
    })
    renderPage()

    const select = screen.getByLabelText('filters.status')
    expect(select.tagName).toBe('SELECT')
  })

  it('renders rows when work orders are present', () => {
    useWorkOrdersMock.mockReturnValue({
      data: { data: [makeWorkOrder()], meta: { total: 1 } },
      isLoading: false,
      isError: false,
      error: null,
    })
    renderPage()

    expect(screen.getByTestId('wo-row')).toHaveTextContent('WO-2026-000001')
  })

  it('renders an alert role on load error', () => {
    useWorkOrdersMock.mockReturnValue({
      data: undefined,
      isLoading: false,
      isError: true,
      error: new Error('boom'),
    })
    renderPage()

    expect(screen.getByRole('alert')).toHaveTextContent('list.errorLoading')
  })
})
