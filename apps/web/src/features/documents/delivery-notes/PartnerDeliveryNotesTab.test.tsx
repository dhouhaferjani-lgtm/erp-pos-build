import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import i18n from '@/lib/i18n'
import { renderWithProviders } from '@/test/renderWithProviders'
import type {
  DeliveryNote,
  PartnerDeliveryNoteFilter,
  PartnerDeliveryNotesResponse,
} from '../api/deliveryNotes'
import { DeliveryNoteBillingStatus } from './DeliveryNoteBillingStatus'
import {
  PartnerDeliveryNotesTab,
  PartnerUnbilledBalanceLine,
} from './PartnerDeliveryNotesTab'

const mockUsePartnerDeliveryNotes = vi.hoisted(() => vi.fn())
const mockMutateAsync = vi.hoisted(() => vi.fn())
const mockFormat = vi.hoisted(() => vi.fn((value: string | number) => `TND ${String(value)}`))

vi.mock('../hooks/useDeliveryNotes', () => ({
  usePartnerDeliveryNotes: mockUsePartnerDeliveryNotes,
  useConsolidateDeliveryNotes: () => ({
    mutateAsync: mockMutateAsync,
    isPending: false,
  }),
}))

vi.mock('@/hooks/useCurrency', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/hooks/useCurrency')>()
  return {
    ...actual,
    useCurrency: () => ({ format: mockFormat }),
  }
})

function deliveryNote(
  id: string,
  overrides: Partial<DeliveryNote> = {},
): DeliveryNote {
  return {
    id,
    document_number: `DN-${id}`,
    type: 'delivery_note',
    status: 'confirmed',
    partner_id: 'partner-1',
    partner_name: 'Acme',
    document_date: '2026-08-01',
    subtotal: '100.000',
    tax_amount: '0.000',
    total: '100.000',
    currency: 'TND',
    lines: [],
    invoiced_at: null,
    invoiced_by_document_id: null,
    invoiced_by_document_number: null,
    invoiced_via: null,
    created_at: '2026-08-01T10:00:00Z',
    updated_at: '2026-08-01T10:00:00Z',
    ...overrides,
  }
}

function response(
  data: DeliveryNote[],
  overrides: Partial<PartnerDeliveryNotesResponse> = {},
): PartnerDeliveryNotesResponse {
  return {
    data,
    meta: {
      current_page: 1,
      last_page: 1,
      total: data.length,
      per_page: 10,
      from: data.length === 0 ? null : 1,
      to: data.length,
    },
    aggregates: { count: data.length, total: '300.000', currency: 'TND' },
    ...overrides,
  }
}

const uninvoicedNotes = [deliveryNote('001'), deliveryNote('002'), deliveryNote('003')]
const invoicedNotes = [
  deliveryNote('004', {
    invoiced_at: '2026-08-10T09:00:00Z',
    invoiced_by_document_id: 'invoice-4',
    invoiced_by_document_number: 'INV-004',
    invoiced_via: 'order_conversion',
  }),
  deliveryNote('005', {
    invoiced_at: '2026-08-11T09:00:00Z',
    invoiced_by_document_id: 'invoice-5',
    invoiced_by_document_number: 'INV-005',
    invoiced_via: 'legacy_unknown',
  }),
]

beforeEach(async () => {
  vi.clearAllMocks()
  await i18n.changeLanguage('en')
  mockUsePartnerDeliveryNotes.mockImplementation((params: { filter: PartnerDeliveryNoteFilter; page: number }) => ({
    data: params.filter === 'invoiced' ? response(invoicedNotes) : response(uninvoicedNotes),
    isLoading: false,
    isError: false,
  }))
  mockMutateAsync.mockResolvedValue({ data: { id: 'invoice-new' } })
})

describe('PartnerDeliveryNotesTab', () => {
  it('defaults to un-invoiced and keeps invoiced rows visible but unselectable with invoice attribution', async () => {
    const user = userEvent.setup()
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    expect(screen.getByRole('button', { name: 'Un-invoiced' })).toHaveAttribute('aria-pressed', 'true')
    await user.click(screen.getByRole('button', { name: 'Invoiced' }))

    const invoicedRow = screen.getByRole('row', { name: /DN-004/ })
    expect(invoicedRow).toHaveClass('opacity-60')
    expect(within(invoicedRow).queryByRole('checkbox')).not.toBeInTheDocument()
    expect(within(invoicedRow).getByRole('link', { name: 'INV-004' })).toHaveAttribute(
      'href',
      '/sales/invoices/invoice-4',
    )
    expect(within(invoicedRow).getByText('Billed from sales order')).toBeInTheDocument()
    expect(screen.getByText('Invoiced (source not recorded)')).toBeInTheDocument()
  })

  it('hides the create action when invoices.create is unavailable', () => {
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice={false} />)

    expect(screen.queryByRole('button', { name: /Create invoice from selected/ })).not.toBeInTheDocument()
  })

  it('marks exactly the two server-named rows, preserves the remainder, and retries only that remainder', async () => {
    const user = userEvent.setup()
    const refusal = {
      response: {
        status: 422,
        data: {
          error: {
            code: 'DELIVERY_NOTE_ALREADY_INVOICED',
            details: {
              documents: [
                { id: '001', document_number: 'DN-001' },
                { id: '002', document_number: 'DN-002' },
              ],
            },
          },
        },
      },
    }
    mockMutateAsync
      .mockRejectedValueOnce(refusal)
      .mockResolvedValueOnce({ data: { id: 'invoice-remainder' } })
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    await user.click(screen.getByRole('checkbox', { name: 'Select all delivery notes' }))
    await user.click(screen.getByRole('button', { name: 'Create invoice from selected (3)' }))

    expect(await screen.findAllByTestId(/^delivery-note-refused-/)).toHaveLength(2)
    expect(screen.getByTestId('delivery-note-refused-001')).toBeInTheDocument()
    expect(screen.getByTestId('delivery-note-refused-002')).toBeInTheDocument()
    expect(screen.queryByTestId('delivery-note-refused-003')).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Select DN-003' })).toBeChecked()

    await user.click(screen.getByRole('button', { name: 'Remove these 2 and retry' }))
    await waitFor(() => {
      expect(mockMutateAsync).toHaveBeenLastCalledWith(['003'])
    })
  })

  it('keeps complete attribution in the alert when a refused delivery note is off page', async () => {
    const user = userEvent.setup()
    mockUsePartnerDeliveryNotes.mockImplementation((params: { page: number }) => ({
      data: response(params.page === 2 ? [deliveryNote('011')] : [deliveryNote('001')], {
        meta: {
          current_page: params.page,
          last_page: 2,
          total: 2,
          per_page: 1,
          from: params.page,
          to: params.page,
        },
      }),
      isLoading: false,
      isError: false,
    }))
    mockMutateAsync.mockRejectedValueOnce({
      response: {
        status: 422,
        data: {
          error: {
            code: 'DELIVERY_NOTE_ALREADY_INVOICED',
            details: {
              documents: [{
                id: '001',
                document_number: 'DN-001',
                invoice_id: 'invoice-1',
                invoice_number: 'INV-001',
                invoice_date: '2026-08-12',
                invoiced_via: 'order_conversion',
              }],
            },
          },
        },
      },
    })
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    await user.click(screen.getByRole('checkbox', { name: 'Select DN-001' }))
    await user.click(screen.getByRole('button', { name: 'Next' }))
    await user.click(await screen.findByRole('checkbox', { name: 'Select DN-011' }))
    await user.click(screen.getByRole('button', { name: 'Create invoice from selected (2)' }))

    const alert = await screen.findByRole('alert')
    expect(within(alert).getByText('DN-001')).toBeInTheDocument()
    expect(within(alert).getByText(/2026-08-12/)).toBeInTheDocument()
    expect(within(alert).getByText(/Billed from sales order/)).toBeInTheDocument()
    expect(within(alert).getByRole('link', { name: 'Open INV-001' })).toHaveAttribute(
      'href',
      '/sales/invoices/invoice-1',
    )
  })

  it('keeps the refusal guarantee visible when every selected row was refused', async () => {
    const user = userEvent.setup()
    mockUsePartnerDeliveryNotes.mockReturnValue({
      data: response([deliveryNote('001')]),
      isLoading: false,
      isError: false,
    })
    mockMutateAsync.mockRejectedValueOnce({
      response: {
        status: 422,
        data: {
          error: {
            code: 'DELIVERY_NOTE_ALREADY_INVOICED',
            details: { documents: [{ id: '001', document_number: 'DN-001' }] },
          },
        },
      },
    })
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    await user.click(screen.getByRole('checkbox', { name: 'Select DN-001' }))
    await user.click(screen.getByRole('button', { name: 'Create invoice from selected (1)' }))
    await user.click(await screen.findByRole('button', { name: /Remove .*1.*retry/ }))

    expect(screen.getByRole('alert')).toHaveTextContent(
      'No invoice was created. No invoice number was used.',
    )
    expect(mockMutateAsync).toHaveBeenCalledTimes(1)
  })

  // Restores the French coverage of the OI-8 refusal surface. The only test that
  // asserted this copy lived on the deleted consolidation component and went with
  // it in 38fdd18f9, leaving `billingRefusal.guarantee` / `.removeAndRetry` — still
  // live product copy on two surfaces — unasserted in fr (M4 round 1, finding 5).
  it('renders the no-artifact guarantee and the selective-retry action in French', async () => {
    const user = userEvent.setup()
    await i18n.changeLanguage('fr')
    mockUsePartnerDeliveryNotes.mockReturnValue({
      data: response([deliveryNote('001')]),
      isLoading: false,
      isError: false,
    })
    mockMutateAsync.mockRejectedValueOnce({
      response: {
        status: 422,
        data: {
          error: {
            code: 'DELIVERY_NOTE_ALREADY_INVOICED',
            details: { documents: [{ id: '001', document_number: 'DN-001' }] },
          },
        },
      },
    })
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    await user.click(screen.getByRole('checkbox', { name: 'Sélectionner DN-001' }))
    await user.click(
      screen.getByRole('button', { name: 'Créer une facture à partir de la sélection (1)' }),
    )

    const refusal = await screen.findByRole('alert')
    expect(refusal).toHaveTextContent(
      'Aucune facture n’a été créée. Aucun numéro de facture n’a été utilisé.',
    )
    expect(
      screen.getByRole('button', { name: 'Retirer ces 1 éléments et réessayer' }),
    ).toBeInTheDocument()
  })

  it('formats each row total in the delivery note currency', () => {
    mockUsePartnerDeliveryNotes.mockReturnValue({
      data: response([deliveryNote('eur', { currency: 'EUR', total: '99.875' })]),
      isLoading: false,
      isError: false,
    })

    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    expect(screen.getByText(/99[,.]88 EUR/)).toBeInTheDocument()
    expect(screen.queryByText('TND 99.875')).not.toBeInTheDocument()
  })

  it('keeps a foreign-currency row visible but outside the company-currency batch', () => {
    mockUsePartnerDeliveryNotes.mockReturnValue({
      data: response([
        deliveryNote('tnd'),
        deliveryNote('eur', { currency: 'EUR', total: '99.875' }),
      ], { aggregates: { count: 1, total: '100.000', currency: 'TND' } }),
      isLoading: false,
      isError: false,
    })

    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    expect(screen.getByText('DN-eur')).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'Select DN-eur' })).not.toBeInTheDocument()
    expect(screen.getByRole('checkbox', { name: 'Select DN-tnd' })).toBeInTheDocument()
  })

  it('discloses that aggregate count and total are company-currency scoped', () => {
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    expect(screen.getByText('(3 delivery notes in TND)')).toBeInTheDocument()
  })

  it('renders the complete four-line coexistence guidance', () => {
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    expect(screen.getByText(/Bill from the sales order when the whole order goes out/)).toBeInTheDocument()
    expect(screen.getByText(/Bill from delivery notes when you deliver repeatedly/)).toBeInTheDocument()
  })

  it('reaches the second offset page', async () => {
    const user = userEvent.setup()
    mockUsePartnerDeliveryNotes.mockImplementation((params: { page: number }) => ({
      data: response(params.page === 2 ? [deliveryNote('011')] : uninvoicedNotes, {
        meta: {
          current_page: params.page,
          last_page: 2,
          total: 11,
          per_page: 10,
          from: params.page === 2 ? 11 : 1,
          to: params.page === 2 ? 11 : 10,
        },
      }),
      isLoading: false,
      isError: false,
    }))
    renderWithProviders(<PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />)

    await user.click(screen.getByRole('button', { name: 'Next' }))

    expect(await screen.findByText('DN-011')).toBeInTheDocument()
    expect(mockUsePartnerDeliveryNotes).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }))
  })
})

describe('PartnerUnbilledBalanceLine', () => {
  it('formats aggregate total without numeric coercion and links to the delivery-note tab', () => {
    mockUsePartnerDeliveryNotes.mockReturnValue({
      data: response(uninvoicedNotes, {
        aggregates: { count: 3, total: '300.750', currency: 'TND' },
      }),
      isLoading: false,
      isError: false,
    })

    const { container } = renderWithProviders(<PartnerUnbilledBalanceLine partnerId="partner-1" />, {
      route: '/sales/customers/partner-1',
    })

    expect(mockFormat).toHaveBeenCalledWith('300.750')
    expect(screen.getByRole('link', { name: /Delivered, not yet invoiced/ })).toHaveAttribute(
      'href',
      '/sales/customers/partner-1?tab=delivery-notes',
    )
    expect(screen.getByText('TND 300.750')).toBeInTheDocument()
    expect(screen.getByText('(3 delivery notes in TND)')).toBeInTheDocument()
    expect(container.querySelector('dt')).toBeInTheDocument()
    expect(container.querySelector('dd')).toBeInTheDocument()
  })

  it('shows the same aggregate total in the balance line and default tab summary', () => {
    mockUsePartnerDeliveryNotes.mockReturnValue({
      data: response(uninvoicedNotes, {
        aggregates: { count: 3, total: '300.750', currency: 'TND' },
      }),
      isLoading: false,
      isError: false,
    })

    renderWithProviders(
      <>
        <PartnerUnbilledBalanceLine partnerId="partner-1" />
        <PartnerDeliveryNotesTab partnerId="partner-1" canCreateInvoice />
      </>,
      { route: '/sales/customers/partner-1' },
    )

    expect(screen.getAllByText('TND 300.750')).toHaveLength(2)
    expect(mockFormat).toHaveBeenCalledWith('300.750')
  })
})

describe('DeliveryNoteBillingStatus', () => {
  it('renders the owner-ruled neutral legacy label in English and French', async () => {
    const legacy = invoicedNotes[1]
    const firstRender = renderWithProviders(<DeliveryNoteBillingStatus deliveryNote={legacy} />)

    expect(screen.getByText('Invoiced (source not recorded)')).toBeInTheDocument()

    firstRender.unmount()
    await i18n.changeLanguage('fr')
    const frenchRender = renderWithProviders(<DeliveryNoteBillingStatus deliveryNote={legacy} />)

    expect(screen.getByText('Facturé (origine non enregistrée)')).toBeInTheDocument()
    frenchRender.unmount()
    await i18n.changeLanguage('en')
  })
})
