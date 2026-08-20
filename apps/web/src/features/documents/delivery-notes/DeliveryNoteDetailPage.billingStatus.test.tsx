import { Route, Routes } from 'react-router-dom'
import { screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { DeliveryNoteDetailPage } from './DeliveryNoteDetailPage'

const mockApi = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return { ...actual, api: mockApi }
})

vi.mock('../hooks/useDocumentPdf', () => ({
  useDownloadPdf: () => ({ mutate: vi.fn(), isPending: false }),
  usePreviewPdf: () => ({ mutate: vi.fn(), isPending: false }),
  usePrintPdf: () => ({ mutate: vi.fn(), isPending: false }),
}))

vi.mock('../hooks/useDocumentEmail', () => ({
  useSendDocumentEmail: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

vi.mock('../components/DocumentActionBar', () => ({
  DocumentActionBar: () => null,
}))

vi.mock('../components/RelatedDocumentsTab', () => ({
  RelatedDocumentsTab: () => null,
}))

const billedDeliveryNote = {
  id: 'delivery-note-1',
  document_number: 'DN-001',
  type: 'delivery_note',
  status: 'confirmed',
  partner_id: 'partner-1',
  partner_name: 'Acme',
  partner_email: null,
  document_date: '2026-08-01',
  vehicle_context: null,
  lines: [],
  subtotal: '100.000',
  tax_amount: '0.000',
  total: '100.000',
  currency: 'TND',
  invoiced_at: '2026-08-10T09:00:00Z',
  invoiced_by_document_id: 'invoice-1',
  invoiced_by_document_number: 'INV-001',
  invoiced_via: 'order_conversion',
}

beforeEach(() => {
  vi.clearAllMocks()
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: 'tenant-A',
      roles: ['admin'],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [{
      id: 'company-1',
      name: 'Test Company',
      legalName: 'Test Company',
      taxId: null,
      countryCode: 'TN',
      currency: 'TND',
      locale: 'en_US',
      timezone: 'Africa/Tunis',
    }],
    isLoading: false,
  })
  mockApi.get.mockResolvedValue({ data: { data: billedDeliveryNote } })
})

describe('DeliveryNoteDetailPage billing state', () => {
  it('shows the invoice link and the lane that consumed the delivery note', async () => {
    renderWithProviders(
      <Routes>
        <Route path="/inventory/delivery-notes/:id" element={<DeliveryNoteDetailPage />} />
      </Routes>,
      { route: '/inventory/delivery-notes/delivery-note-1' },
    )

    expect(await screen.findByText('Invoiced on')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'INV-001' })).toHaveAttribute(
      'href',
      '/sales/invoices/invoice-1',
    )
    expect(screen.getByText('Billed from sales order')).toBeInTheDocument()
  })

  it('does not render billing attribution for an un-invoiced delivery note', async () => {
    mockApi.get.mockResolvedValue({
      data: {
        data: {
          ...billedDeliveryNote,
          invoiced_at: null,
          invoiced_by_document_id: null,
          invoiced_by_document_number: null,
          invoiced_via: null,
        },
      },
    })
    renderWithProviders(
      <Routes>
        <Route path="/inventory/delivery-notes/:id" element={<DeliveryNoteDetailPage />} />
      </Routes>,
      { route: '/inventory/delivery-notes/delivery-note-1' },
    )

    expect(await screen.findByText('DN-001')).toBeInTheDocument()
    expect(screen.queryByText('Invoiced on')).not.toBeInTheDocument()
  })
})
