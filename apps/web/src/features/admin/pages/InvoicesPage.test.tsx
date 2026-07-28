import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { InvoicesPage } from './InvoicesPage'
import type { Invoice } from '../types'

const invoice: Invoice = {
  id: 'invoice-1',
  tenant_id: 'tenant-1',
  subscription_id: null,
  number: 'INV-001',
  status: 'sent',
  subtotal: '15.000',
  tax_amount: '0.000',
  discount_amount: '0.000',
  total: '15.000',
  amount_paid: '0.000',
  amount_due: '15.000',
  currency: 'EUR',
  tax_rate: '0.00',
  billing_name: 'Tenant',
  billing_email: 'tenant@example.com',
  billing_address: {},
  invoice_date: '2026-07-01',
  due_date: '2026-07-31',
  paid_at: null,
  sent_at: null,
  period_start: null,
  period_end: null,
  pdf_path: null,
  notes: null,
  created_at: '2026-07-01T00:00:00Z',
  items: [
    {
      id: 'item-1',
      invoice_id: 'invoice-1',
      description: 'Platform seats',
      long_description: null,
      quantity: '1.5',
      quantity_decimals: 2,
      unit_price: '10.000',
      amount: '15.000',
      tax_rate: '0.00',
      tax_amount: '0.000',
      period_start: null,
      period_end: null,
    },
  ],
}

vi.mock('../hooks/useBilling', () => ({
  useInvoices: () => ({
    data: { data: [invoice], total: 1 },
    isLoading: false,
  }),
  useCreateInvoice: () => ({ mutate: vi.fn() }),
}))

vi.mock('../api', () => ({
  downloadInvoicePdf: vi.fn(),
}))

describe('InvoicesPage', () => {
  it('formats CENTRAL billing item quantities at the served billing scale', () => {
    render(
      <MemoryRouter>
        <InvoicesPage />
      </MemoryRouter>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'View' }))

    expect(screen.getByText('1.50')).toBeInTheDocument()
  })

  it('edits CENTRAL billing quantities with the fixed billing scale', () => {
    render(
      <MemoryRouter>
        <InvoicesPage />
      </MemoryRouter>,
    )

    fireEvent.click(screen.getByRole('button', { name: 'Create Invoice' }))

    const quantityInput = screen.getByPlaceholderText('Qty')
    expect(quantityInput).toHaveAttribute('step', '0.01')

    fireEvent.change(quantityInput, { target: { value: '1.50' } })

    expect(quantityInput).toHaveAttribute('value', '1.50')
  })
})
