import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { WorkOrderLineRow } from './WorkOrderLineRow'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('WorkOrderLineRow', () => {
  it('formats quantities with the served work-order line scale', () => {
    render(
      <WorkOrderLineRow
        currency="TND"
        redactFinancials={false}
        line={{
          id: 'line-1',
          work_order_id: 'work-order-1',
          line_type: 'part',
          display_order: 0,
          product_id: 'product-1',
          service_id: null,
          service_bundle_id: null,
          display_name: 'Precision part',
          sku_or_code: 'SKU-1',
          description: null,
          quantity: '1.25',
          quantity_decimals: 3,
          unit: 'kg',
          unit_price: '10.000',
          tax_rate: '19.00',
          discount_percent: '0.00',
          line_total_excl_tax: '12.500',
          line_total_tax: '2.375',
          line_total_incl_tax: '14.875',
          labor_hours_estimated: null,
          labor_hours_actual: null,
          assigned_technician_profile_id: null,
          stock_reservation_id: null,
          is_customer_supplied: false,
          core_deposit_partner_id: null,
          core_deposit_status: null,
          core_return_of_line_id: null,
          from_bundle_id: null,
          is_bundle_informational: false,
          is_completed: false,
          completed_at: null,
        }}
      />,
    )

    expect(screen.getByText(/1\.250 kg/)).toBeInTheDocument()
  })
})
