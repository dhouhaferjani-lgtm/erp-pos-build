/**
 * Fixture factory for OpenInvoicesList tests.
 *
 * Typed against the hand-written `OpenInvoice` interface in
 * `@/types/treasury`.
 */

import type { OpenInvoice } from '@/types/treasury'

export function makeOpenInvoice(
  overrides: Partial<OpenInvoice> = {},
): OpenInvoice {
  return {
    id: '1',
    document_number: 'INV-00001',
    document_date: '2025-11-01',
    due_date: '2025-11-30',
    total: '1190.0000',
    balance_due: '1190.0000',
    currency: 'TND',
    days_overdue: 10,
    partner: {
      id: 'partner-1',
      name: 'Customer A',
    },
    ...overrides,
  }
}
