import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import type { Document } from '@/types/document'

import { DocumentActionBar } from '../DocumentActionBar'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

function makeDocument(overrides: Partial<Document> = {}): Document {
  return {
    id: 'doc-1',
    type: 'quote',
    status: 'confirmed',
    document_number: 'Q-2026-0001',
    document_date: '2026-07-06',
    due_date: null,
    valid_until: null,
    currency: 'TND',
    subtotal: '100.000',
    tax_amount: '19.000',
    total: '119.000',
    notes: null,
    internal_notes: null,
    partner_id: 'partner-1',
    partner_name: 'Client',
    partner_email: null,
    source_document_id: null,
    source_document_number: null,
    source_document_type: null,
    converted_to_order_id: null,
    fully_delivered: false,
    fully_invoiced: false,
    goods_received: false,
    payment_status: 'unpaid',
    amount_paid: '0.000',
    balance_due: '119.000',
    outstanding_amount: '119.000',
    external_document_number: null,
    external_document_date: null,
    vehicle_context: null,
    created_at: '2026-07-06T00:00:00Z',
    updated_at: '2026-07-06T00:00:00Z',
    ...overrides,
  }
}

function setRoles(roles: string[]) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-1',
      roles,
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
}

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
})

describe('DocumentActionBar', () => {
  it('shows revert to draft for supported confirmed documents with documents.update', async () => {
    const user = userEvent.setup()
    const onRevert = vi.fn()
    setRoles(['admin'])

    render(
      <MemoryRouter>
        <DocumentActionBar
          document={makeDocument()}
          basePath="/sales/quotes"
          isActionPending={false}
          onRevert={onRevert}
        />
      </MemoryRouter>,
    )

    await user.click(screen.getByRole('button', { name: 'documents.revertToDraft' }))

    expect(onRevert).toHaveBeenCalledTimes(1)
  })

  it('hides revert to draft without documents.update', () => {
    setRoles(['viewer'])

    render(
      <MemoryRouter>
        <DocumentActionBar
          document={makeDocument()}
          basePath="/sales/quotes"
          isActionPending={false}
          onRevert={vi.fn()}
        />
      </MemoryRouter>,
    )

    expect(screen.queryByRole('button', { name: 'documents.revertToDraft' })).not.toBeInTheDocument()
  })
})
