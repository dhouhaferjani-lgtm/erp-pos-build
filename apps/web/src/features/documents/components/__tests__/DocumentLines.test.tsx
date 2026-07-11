import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { DocumentLines } from '../DocumentLines'
import type { DocumentLine } from '../../../../types/document'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => fallback ?? key,
  }),
}))

vi.mock('../../hooks/useLineDesignationFeature', () => ({
  useLineDesignationFeature: () => false,
}))

const line: DocumentLine = {
  id: 'line-1',
  document_id: 'doc-1',
  product_id: 'product-1',
  product_name: 'Brake pads',
  product_code: 'BRK-1',
  product_barcode: null,
  primary_image_url: null,
  line_number: 1,
  description: 'Brake pads',
  quantity: '2.0000',
  unit_price: '10.000',
  discount_percent: null,
  discount_amount: null,
  tax_rate: '19.00',
  line_total: '23.800',
  notes: null,
  designation_default_snapshot: 'Brake pads',
  quantity_decimals: 0,
  requires_batch_tracking: false,
}

describe('DocumentLines', () => {
  it('formats quantity using the line precision instead of rendering raw backend scale', () => {
    render(
      <MemoryRouter>
        <DocumentLines lines={[line]} formatAmount={(amount) => String(amount)} showProductLinks={false} />
      </MemoryRouter>,
    )

    expect(screen.getByText('2')).toBeInTheDocument()
    expect(screen.queryByText('2.0000')).not.toBeInTheDocument()
  })

  it('does not repeat the product name as the article sub-line when the description is unchanged', () => {
    render(
      <MemoryRouter>
        <DocumentLines lines={[line]} formatAmount={(amount) => String(amount)} showProductLinks={false} />
      </MemoryRouter>,
    )

    expect(screen.getAllByText('Brake pads')).toHaveLength(1)
  })

  it('hides line notes when the line designation feature is disabled', () => {
    render(
      <MemoryRouter>
        <DocumentLines
          lines={[{ ...line, description: 'Front axle ceramic pads', notes: 'Internal restock note' }]}
          formatAmount={(amount) => String(amount)}
          showProductLinks={false}
        />
      </MemoryRouter>,
    )

    expect(screen.getByText('Front axle ceramic pads')).toBeInTheDocument()
    expect(screen.queryByText('Internal restock note')).not.toBeInTheDocument()
  })
})
