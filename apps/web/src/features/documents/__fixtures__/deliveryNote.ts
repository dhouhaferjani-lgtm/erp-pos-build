/**
 * Fixture factory for DeliveryNoteSearchSelect tests.
 *
 * Mirrors the hand-written `DeliveryNote` interface in
 * `@/components/molecules/pickers/DeliveryNoteSearchSelect`. Exposed here (not colocated
 * with the component) because the component lives in shared UI-space and
 * the fixture is dominated by document-feature semantics.
 */

export interface DeliveryNoteLine {
  id: string
  product_code: string
  quantity: number
}

export interface DeliveryNotePartner {
  id: string
  name: string
}

export interface DeliveryNote {
  id: string
  document_number: string
  document_date: string
  partner?: DeliveryNotePartner | null
  total: string
  status: string
  lines?: DeliveryNoteLine[]
}

export function makeDeliveryNote(
  overrides: Partial<DeliveryNote> = {},
): DeliveryNote {
  return {
    id: '1',
    document_number: 'DN-001',
    document_date: '2024-01-15',
    partner: { id: 'p1', name: 'ACME Corp' },
    total: '1500.00',
    status: 'confirmed',
    lines: [{ id: 'l1', product_code: 'PROD-1', quantity: 1 }],
    ...overrides,
  }
}
