/**
 * Fixture factory for InvoiceSearchSelect tests.
 *
 * Types match the `Invoice` interface exported from
 * `@/components/molecules/pickers/InvoiceSearchSelect`. We re-export the factory from the
 * documents feature so unit tests for the search select can share the same
 * fixture shape with other documents-space tests.
 */

import type { Invoice } from '@/components/molecules/pickers/InvoiceSearchSelect'

export function makeInvoice(overrides: Partial<Invoice> = {}): Invoice {
  return {
    id: '1',
    number: 'INV-00001',
    document_date: '2024-12-20',
    total: '1000.00',
    balance: '500.00',
    currency: 'EUR',
    status: 'posted',
    partner_id: 'p1',
    partner: {
      id: 'p1',
      name: 'ACME Corp',
    },
    ...overrides,
  }
}
