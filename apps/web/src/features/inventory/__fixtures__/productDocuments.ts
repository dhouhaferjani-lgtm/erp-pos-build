/**
 * Fixture factories for ProductDocumentsTab tests.
 *
 * Types mirror the hand-written `Document`/`DocumentLine` interfaces declared
 * in `../components/ProductDocumentsTab.tsx`. The endpoint is called via
 * `api.get<DocumentsResponse>('/documents?...')`, so tests resolve the mock
 * with `{ data: { data: [...] } }`.
 */

export interface ProductDocumentLine {
  id: string
  product_id: string | null
  description: string
  quantity: string
  unit_price: string
  line_total: string
  landed_unit_cost?: string | null
}

export interface ProductDocument {
  id: string
  type: string
  status: string
  document_number: string | null
  document_date: string
  partner_id: string | null
  // Accept `undefined` so tests for the "missing partner" code path can
  // spread with `partner_name: undefined` (exactOptionalPropertyTypes).
  partner_name?: string | undefined
  total: string
  currency: string
  lines?: ProductDocumentLine[]
}

export function makeProductDocumentLine(
  overrides: Partial<ProductDocumentLine> = {},
): ProductDocumentLine {
  return {
    id: 'line-1',
    product_id: 'prod-1',
    description: 'Test Product',
    quantity: '5',
    unit_price: '100.00',
    line_total: '500.00',
    landed_unit_cost: null,
    ...overrides,
  }
}

export function makeProductDocument(
  overrides: Partial<ProductDocument> = {},
): ProductDocument {
  return {
    id: 'doc-1',
    type: 'invoice',
    status: 'posted',
    document_number: 'INV-2024-001',
    document_date: '2024-01-15',
    partner_id: 'partner-1',
    partner_name: 'Acme Corp',
    total: '1500.00',
    currency: 'EUR',
    lines: [makeProductDocumentLine()],
    ...overrides,
  }
}
