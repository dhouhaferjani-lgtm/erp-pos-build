import { describe, it, expect, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { RelatedDocumentsTab } from '../RelatedDocumentsTab'
import type { DocumentChain } from '../../hooks/useRelatedDocuments'

const chain: DocumentChain = {
  ancestors: [],
  current: {
    id: 'inv-1',
    type: 'invoice',
    document_number: 'INV-2024-001',
    document_date: '2024-01-15',
    status: 'posted',
    total: '100.000',
    currency: 'EUR',
  },
  descendants: [
    {
      id: 'dn-1',
      type: 'delivery_note',
      document_number: 'DN-2024-001',
      document_date: '2024-01-16',
      status: 'posted',
      total: '100.000',
      currency: 'EUR',
    },
  ],
}

vi.mock('../../hooks/useRelatedDocuments', () => ({
  useRelatedDocuments: () => ({ data: chain, isLoading: false, error: null }),
}))

describe('RelatedDocumentsTab', () => {
  it('links delivery notes to the inventory delivery-note detail route', () => {
    renderWithProviders(<RelatedDocumentsTab documentId="inv-1" />)

    const links = screen.getAllByRole('link')
    const hrefs = links.map((link) => link.getAttribute('href'))

    expect(hrefs).toContain('/inventory/delivery-notes/dn-1')
    // The old broken route must not appear.
    expect(hrefs).not.toContain('/sales/delivery-notes/dn-1')
  })
})
