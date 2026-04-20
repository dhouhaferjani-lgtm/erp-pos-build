/**
 * Fixture factories for credit-note form tests.
 *
 * Typed against the hand-written types in `@/types/creditNote`. Those
 * interfaces are the contract the `CreateCreditNoteForm` prop shape depends
 * on, so when the interface changes this factory will fail-compile.
 */

import type { InvoiceForCreditNote } from '@/types/creditNote'
import { DocumentStatus } from '@/types/creditNote'

export function makeInvoiceForCreditNote(
  overrides: Partial<InvoiceForCreditNote> = {},
): InvoiceForCreditNote {
  return {
    id: 'invoice-1',
    document_number: 'INV-00001',
    document_date: '2025-12-01',
    partner: {
      id: 'partner-1',
      name: 'Test Customer',
    },
    total: '1190.0000',
    balance_due: '1190.0000',
    currency: 'TND',
    status: DocumentStatus.POSTED,
    ...overrides,
  }
}
