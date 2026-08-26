/**
 * C-F0w / SPEC §2.4 — the credit note print surface reads the PREDICATE.
 *
 * TDD: written before `is_proforma` reached this component.
 *
 * `CreditNoteDetail` owns the only in-browser document PRINT surface
 * (`window.print()`), and it decided from `status` because that was the only
 * signal the API gave it — its own comment conceded "if a credit note is ever
 * POSTED without a seal, this view cannot tell". The first case below IS that
 * hole: a `POSTED`, never-sealed credit note. A status heuristic calls it
 * definitive and prints it as one.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { expectNoForbiddenToken, translateFrom } from '@/test/proformaTokens'
import enSales from '@/locales/en/sales.json'
import enCommon from '@/locales/en/common.json'
import { CreditNoteDetail } from './CreditNoteDetail'
import { CreditNoteReason, DocumentStatus, type CreditNote } from '@/types/creditNote'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: translateFrom(
      {
        sales: enSales as Record<string, unknown>,
        common: enCommon as Record<string, unknown>,
      },
      'sales'
    ),
  }),
}))

const createQueryClient = () =>
  new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })

const wrapper = ({ children }: { children: React.ReactNode }) => (
  <QueryClientProvider client={createQueryClient()}>{children}</QueryClientProvider>
)

const baseCreditNote: CreditNote = {
  id: 'cn-1',
  document_number: 'CN-00001',
  source_invoice_id: 'inv-1',
  source_invoice_number: 'INV-00001',
  partner: { id: 'partner-1', name: 'Test Customer' },
  document_date: '2026-01-15',
  currency: 'TND',
  subtotal: '200.000',
  tax_amount: '38.000',
  total: '238.000',
  reason: CreditNoteReason.RETURN,
  reason_label: 'Product Return',
  notes: null,
  status: DocumentStatus.POSTED,
  created_at: '2026-01-15T10:00:00Z',
}

const renderDetail = (creditNote: CreditNote) =>
  render(<CreditNoteDetail creditNote={creditNote} />, { wrapper })

describe('CreditNoteDetail — proforma branch', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('banners a POSTED but never-sealed credit note — the hole the heuristic admitted', () => {
    renderDetail({ ...baseCreditNote, status: DocumentStatus.POSTED, is_proforma: true })

    expect(screen.getByText('Proforma — non-fiscal document')).toBeInTheDocument()
  })

  it('carries no forbidden token anywhere in the printed DOM of a proforma', () => {
    const { container } = renderDetail({
      ...baseCreditNote,
      status: DocumentStatus.POSTED,
      is_proforma: true,
    })

    expectNoForbiddenToken(container.innerHTML, 'the proforma credit note print DOM')
  })

  it('shows no status badge and no status field on a proforma', () => {
    renderDetail({ ...baseCreditNote, status: DocumentStatus.POSTED, is_proforma: true })

    expect(screen.queryAllByText('Posted')).toHaveLength(0)
    expect(screen.queryAllByText('Draft')).toHaveLength(0)
    expect(screen.queryByText('Status')).not.toBeInTheDocument()
  })

  it('does NOT banner a DRAFT credit note the server calls definitive', () => {
    // The retired heuristic bannered every non-`POSTED` document. The predicate
    // is the seal, and the server is the only thing that knows it.
    renderDetail({ ...baseCreditNote, status: DocumentStatus.DRAFT, is_proforma: false })

    expect(screen.queryByText('Proforma — non-fiscal document')).not.toBeInTheDocument()
    // The badge AND the status field come back: both are absent only on a proforma.
    expect(screen.getAllByText('Draft')).toHaveLength(2)
  })

  it('keeps the cancelled marker for a sealed-then-cancelled credit note', () => {
    renderDetail({
      ...baseCreditNote,
      status: DocumentStatus.CANCELLED,
      is_proforma: false,
    })

    expect(
      screen.getByText('Cancelled — this document has been voided')
    ).toBeInTheDocument()
    expect(screen.queryByText('Proforma — non-fiscal document')).not.toBeInTheDocument()
  })

  it('says a DEFINITIVE credit note reduces the balance, and a proforma does not', () => {
    const balanceNote = 'This credit note reduces your balance by the amount shown above.'

    const definitive = renderDetail({ ...baseCreditNote, is_proforma: false })
    expect(screen.getByText(balanceNote)).toBeInTheDocument()
    definitive.unmount()

    renderDetail({ ...baseCreditNote, is_proforma: true })
    expect(screen.queryByText(balanceNote)).not.toBeInTheDocument()
  })

  it('formats the amount without ever parsing money into a float', () => {
    renderDetail({ ...baseCreditNote, total: '238.000', is_proforma: false })

    // TND is a 3-decimal currency: `parseFloat(...).toFixed(2)` printed 238.00
    // and silently truncated a millime.
    expect(screen.getByText(/238[.,]000/)).toBeInTheDocument()
  })
})
