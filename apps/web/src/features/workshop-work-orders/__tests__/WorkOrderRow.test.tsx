import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { WorkOrderRow } from '../components/WorkOrderRow'
import type { WorkOrderListItem } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

function makeItem(overrides: Partial<WorkOrderListItem> = {}): WorkOrderListItem {
  return {
    id: '11111111-1111-1111-1111-111111111111',
    work_order_number: 'WO-2026-000001',
    status: 'received',
    type: 'repair',
    customer_display_name: 'Acme Motors',
    vehicle_display_name: 'Peugeot 208 ABC-123',
    primary_technician_display_name: 'Alice Wrench',
    scheduled_start_at: '2026-04-20T09:00:00Z',
    promised_at: null,
    currency: 'TND',
    estimated_grand_total: '250.000',
    actual_grand_total: null,
    created_at: '2026-04-19T12:00:00Z',
    ...overrides,
  }
}

describe('WorkOrderRow', () => {
  it('renders the work order number, customer, and totals', () => {
    render(
      <MemoryRouter>
        <WorkOrderRow workOrder={makeItem()} />
      </MemoryRouter>
    )
    expect(screen.getByText('WO-2026-000001')).toBeInTheDocument()
    expect(screen.getByText('Acme Motors')).toBeInTheDocument()
    expect(screen.getByText('Peugeot 208 ABC-123')).toBeInTheDocument()
    expect(screen.getByText('250.000 TND')).toBeInTheDocument()
  })

  it('renders the redaction placeholder when grand total is null', () => {
    render(
      <MemoryRouter>
        <WorkOrderRow workOrder={makeItem({ estimated_grand_total: null })} />
      </MemoryRouter>
    )
    expect(screen.getByText('labels.notSet')).toBeInTheDocument()
  })
})
