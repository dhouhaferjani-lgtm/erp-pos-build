import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { DocumentActionBar } from '../DocumentActionBar'
import type { Document } from '@/types/document'

const hasPermission = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission }),
}))

vi.mock('../../../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission }),
}))

function makeDocument(overrides: Partial<Document> = {}): Document {
  return {
    id: 'inv-1',
    type: 'invoice',
    status: 'posted',
    document_number: 'INV-2026-0001',
    document_date: '2026-08-01',
    due_date: null,
    valid_until: null,
    currency: 'TND',
    subtotal: '100.000',
    tax_amount: '19.000',
    total: '119.000',
    notes: null,
    internal_notes: null,
    partner_id: 'partner-1',
    partner_name: 'Customer',
    partner_email: null,
    source_document_id: null,
    source_document_number: null,
    source_document_type: null,
    converted_to_order_id: null,
    fully_delivered: null,
    fully_invoiced: null,
    goods_received: null,
    payment_status: null,
    amount_paid: null,
    balance_due: null,
    outstanding_amount: null,
    external_document_number: null,
    external_document_date: null,
    vehicle_context: null,
    ...overrides,
  } as Document
}

function renderBar(props: Partial<Parameters<typeof DocumentActionBar>[0]> = {}) {
  return render(
    <MemoryRouter>
      <DocumentActionBar
        document={makeDocument()}
        basePath="/sales/invoices"
        isActionPending={false}
        {...props}
      />
    </MemoryRouter>,
  )
}

/**
 * T11 (plan CF §3) — the Cancel action on the shared document action bar.
 *
 * The bar is shared by SEVEN detail pages, so the gate has to be exactly
 * `type === 'invoice' && status === 'posted' && hasPermission('invoices.cancel') && onCancel`.
 * Anything looser grows a Cancel button on six pages whose backend has no cancel path —
 * which is precisely the defect in the dead `DocumentActions.tsx`, whose own `canCancel`
 * is `draft || confirmed` and CONTRADICTS the backend.
 */
describe('DocumentActionBar — guided cancel action', () => {
  beforeEach(() => {
    hasPermission.mockReset()
    hasPermission.mockReturnValue(true)
  })

  it('shows Cancel for a posted invoice with the permission and a handler', () => {
    renderBar({ onCancel: vi.fn() })

    expect(screen.getByText('documents.cancel')).toBeInTheDocument()
  })

  it('hides Cancel without the permission', () => {
    hasPermission.mockImplementation((ability: string) => ability !== 'invoices.cancel')

    renderBar({ onCancel: vi.fn() })

    expect(screen.queryByText('documents.cancel')).not.toBeInTheDocument()
  })

  it('hides Cancel with no handler — the house `if (canX && onX)` convention', () => {
    renderBar()

    expect(screen.queryByText('documents.cancel')).not.toBeInTheDocument()
  })

  it('hides Cancel for a non-invoice document type', () => {
    renderBar({ document: makeDocument({ type: 'delivery_note' }), onCancel: vi.fn() })

    expect(screen.queryByText('documents.cancel')).not.toBeInTheDocument()
  })

  it('hides Cancel for a draft invoice — only a POSTED one reaches the cancel path', () => {
    renderBar({ document: makeDocument({ status: 'draft' }), onCancel: vi.fn() })

    expect(screen.queryByText('documents.cancel')).not.toBeInTheDocument()
  })
})
