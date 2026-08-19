import { act, fireEvent, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import i18n from '@/lib/i18n'
import { mechanicCompanyConfig } from '@/test/fixtures/companyConfig'
import { renderWithProviders } from '@/test/renderWithProviders'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { useAuthStore } from '@/stores/authStore'
import { DeliveryNoteConsolidation } from './components/DeliveryNoteConsolidation'

const mockMutateAsync = vi.fn()

vi.mock('./hooks/useDeliveryNotes', () => ({
  calculateConsolidationTotals: () => ({ total: '150.000', lineCount: 3 }),
  groupDeliveryNotesByPartner: (deliveryNotes: { partner_id: string }[]) => new Map([['partner-1', deliveryNotes]]),
  useConsolidateDeliveryNotes: () => ({ isPending: false, mutateAsync: mockMutateAsync }),
  useInvoiceableDeliveryNotes: () => ({
    data: [
      {
        id: 'delivery-note-1',
        partner_id: 'partner-1',
        partner_name: 'Customer',
        document_number: 'DN-001',
        document_date: '2026-08-12',
        currency: 'TND',
        total: '50.000',
      },
      {
        id: 'delivery-note-2',
        partner_id: 'partner-1',
        partner_name: 'Customer',
        document_number: 'DN-002',
        document_date: '2026-08-13',
        currency: 'TND',
        total: '50.000',
      },
      {
        id: 'delivery-note-3',
        partner_id: 'partner-1',
        partner_name: 'Customer',
        document_number: 'DN-003',
        document_date: '2026-08-14',
        currency: 'TND',
        total: '50.000',
      },
    ],
    error: null,
    isLoading: false,
  }),
}))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

function grantInvoiceCreation() {
  const user = useAuthStore.getState().user
  if (user === null) throw new Error('Expected an authenticated test user')

  act(() => {
    useAuthStore.getState().setUser({ ...user, permissions: ['invoices.create'] })
  })
}

function attributedRefusal() {
  return {
    response: {
      status: 422,
      data: {
        error: {
          code: 'DELIVERY_NOTE_ALREADY_INVOICED',
          message: 'Delivery notes have already been invoiced',
          details: {
            documents: [
              {
                id: 'delivery-note-1',
                document_number: 'DN-001',
                reason: 'already_invoiced',
                invoice_id: 'invoice-1',
                invoice_number: 'INV-001',
                invoice_date: '2026-08-18',
                invoiced_via: 'consolidation',
              },
              {
                id: 'delivery-note-2',
                document_number: 'DN-002',
                reason: 'claim_lost',
                invoice_id: 'invoice-2',
                invoice_number: 'INV-002',
                invoice_date: '2026-08-19',
                invoiced_via: 'order_conversion',
              },
            ],
          },
        },
      },
    },
  }
}

describe('DeliveryNoteConsolidation billing refusal', () => {
  beforeEach(async () => {
    mockMutateAsync.mockReset()
    await act(async () => {
      await i18n.changeLanguage('en')
    })
    act(() => {
      seedAuth()
    })
    grantInvoiceCreation()
  })

  afterEach(async () => {
    await act(async () => {
      await i18n.changeLanguage('en')
    })
    act(() => {
      resetAuth()
    })
  })

  it('keeps the attributed 422 visible with each taking invoice, date, lane, and link', async () => {
    mockMutateAsync.mockRejectedValue(attributedRefusal())
    renderWithProviders(<DeliveryNoteConsolidation />, {
      companyConfig: mechanicCompanyConfig,
    })

    fireEvent.click(screen.getAllByRole('checkbox')[0])
    fireEvent.click(screen.getByRole('button', { name: 'Create Invoice' }))

    const refusal = await screen.findByRole('alert')
    expect(refusal).toHaveTextContent('No invoice was created. No invoice number was used.')
    expect(refusal).toHaveTextContent('DN-001')
    expect(refusal).toHaveTextContent('2026-08-18')
    expect(refusal).toHaveTextContent('Billed by consolidation')
    expect(refusal).toHaveTextContent('DN-002')
    expect(refusal).toHaveTextContent('2026-08-19')
    expect(refusal).toHaveTextContent('Billed from sales order')
    expect(screen.getByRole('link', { name: 'Open INV-001' })).toHaveAttribute('href', '/sales/invoices/invoice-1')
    expect(screen.getByRole('link', { name: 'Open INV-002' })).toHaveAttribute('href', '/sales/invoices/invoice-2')

    fireEvent.click(screen.getAllByRole('checkbox')[3])
    fireEvent.click(screen.getAllByRole('checkbox')[3])

    expect(screen.getByRole('alert')).toHaveTextContent('DN-001')
    expect(screen.getByRole('button', { name: 'Remove these 2 and retry' })).toBeInTheDocument()
  })

  it('removes only the response-named delivery notes and retains the remaining selection', async () => {
    mockMutateAsync.mockRejectedValue(attributedRefusal())
    renderWithProviders(<DeliveryNoteConsolidation />, {
      companyConfig: mechanicCompanyConfig,
    })

    fireEvent.click(screen.getAllByRole('checkbox')[0])
    fireEvent.click(screen.getByRole('button', { name: 'Create Invoice' }))
    fireEvent.click(await screen.findByRole('button', { name: 'Remove these 2 and retry' }))

    await waitFor(() => {
      const checkboxes = screen.getAllByRole('checkbox')
      expect(checkboxes[1]).not.toBeChecked()
      expect(checkboxes[2]).not.toBeChecked()
      expect(checkboxes[3]).toBeChecked()
    })
    expect(screen.getByText(/1 delivery note selected/)).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('renders the no-artifact and selective-retry recovery in French', async () => {
    await act(async () => {
      await i18n.changeLanguage('fr')
    })
    mockMutateAsync.mockRejectedValue(attributedRefusal())
    renderWithProviders(<DeliveryNoteConsolidation />, {
      companyConfig: mechanicCompanyConfig,
    })

    fireEvent.click(screen.getAllByRole('checkbox')[0])
    fireEvent.click(screen.getByRole('button', { name: 'Créer la facture' }))

    const refusal = await screen.findByRole('alert')
    expect(refusal).toHaveTextContent("Aucune facture n’a été créée. Aucun numéro de facture n’a été utilisé.")
    expect(screen.getByRole('button', { name: 'Retirer ces 2 éléments et réessayer' })).toBeInTheDocument()
  })
})
