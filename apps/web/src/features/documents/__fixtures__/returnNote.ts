/**
 * Fixture factories for return-note form tests.
 *
 * The return-note form accepts a `sourceDocument` prop whose shape is
 * inlined in `CreateReturnNoteForm.tsx`. This factory mirrors that shape
 * so any future change to required fields fail-compiles here.
 */

export interface SourceDocumentLine {
  id: string
  product_id: string
  product_code: string
  product_name: string
  description: string
  quantity: number
  unit_price: string
  tax_rate: string
  total: string
}

export interface SourceDocument {
  id: string
  document_number: string
  document_date: string
  partner_name: string
  total: string
  lines?: SourceDocumentLine[]
}

export function makeSourceDocumentLine(
  overrides: Partial<SourceDocumentLine> = {},
): SourceDocumentLine {
  return {
    id: 'line-1',
    product_id: 'p1',
    product_code: 'PROD-1',
    product_name: 'Product 1',
    description: 'Test product 1',
    quantity: 10,
    unit_price: '100.00',
    tax_rate: '20.00',
    total: '1200.00',
    ...overrides,
  }
}

// `lines` is optional on the component's contract, so allow tests to
// spread `lines: undefined` to drop the default two lines under
// `exactOptionalPropertyTypes: true`.
type SourceDocumentOverrides = Partial<Omit<SourceDocument, 'lines'>> & {
  lines?: SourceDocumentLine[] | undefined
}

export function makeSourceDocument(
  overrides: SourceDocumentOverrides = {},
): SourceDocument {
  const base: SourceDocument = {
    id: 'doc-1',
    document_number: 'INV-001',
    document_date: '2024-01-15',
    partner_name: 'ACME Corp',
    total: '1500.00',
    lines: [
      makeSourceDocumentLine(),
      makeSourceDocumentLine({
        id: 'line-2',
        product_id: 'p2',
        product_code: 'PROD-2',
        product_name: 'Product 2',
        description: 'Test product 2',
        quantity: 5,
        unit_price: '60.00',
        tax_rate: '20.00',
        total: '360.00',
      }),
    ],
  }
  // Honour an explicit `lines: undefined` override (tests use it to
  // simulate a document without line items). Under
  // `exactOptionalPropertyTypes: true` we must delete the key rather
  // than assign `undefined`.
  if ('lines' in overrides && overrides.lines === undefined) {
    const { lines: _droppedLines, ...rest } = overrides
    void _droppedLines
    const { lines: _baseLines, ...baseWithoutLines } = base
    void _baseLines
    return { ...baseWithoutLines, ...rest } as SourceDocument
  }
  return { ...base, ...overrides } as SourceDocument
}
