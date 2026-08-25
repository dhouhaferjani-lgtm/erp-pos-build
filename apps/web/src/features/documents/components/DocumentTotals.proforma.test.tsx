/**
 * C-F0w / SPEC §2.4 — the totals box of a PROFORMA carries no tax.
 *
 * TDD: written before the `isProforma` branch existed.
 *
 * The scan is over RENDERED TEXT with REAL `en` copy (rule 17 + the reason
 * `proformaTokens.ts` gives): an identity `t` would render `documents.subtotal`
 * and hide every forbidden token from the assertion.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { expectNoForbiddenToken, translateFrom } from '@/test/proformaTokens'
import enSales from '@/locales/en/sales.json'
import { DocumentTotals } from './DocumentTotals'
import type { ProformaPresentation } from '@/types/document'
import * as taxApi from '../api/taxApi'

vi.mock('../api/taxApi')

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: translateFrom({ sales: enSales as Record<string, unknown> }, 'sales'),
  }),
}))

const proformaTotals: ProformaPresentation = {
  estimated_total: '238.000',
  gross_lines: '238.000',
  stamp_duty: null,
  discount: null,
  adjustment: null,
  lines: [{ line_id: 'line-1', unit_price: '119.000', line_total: '238.000' }],
}

const definitiveBreakdown: taxApi.TaxBreakdown = {
  subtotal: '200.000',
  discount: '0.000',
  line_tax_amount: '38.000',
  stamp_duty_amount: '0.000',
  total_tax_amount: '38.000',
  total: '238.000',
  tax_details: [
    {
      tax_type: 'percentage',
      tax_name: 'TVA',
      tax_rate: '19.00',
      tax_base: '200.000',
      tax_amount: '38.000',
    },
  ],
}

describe('DocumentTotals — proforma branch', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  const renderTotals = (props: Record<string, unknown>) =>
    render(
      <QueryClientProvider client={queryClient}>
        <DocumentTotals
          documentId="doc-1"
          documentType="invoice"
          currency="TND"
          {...props}
        />
      </QueryClientProvider>
    )

  it('renders not one forbidden token for an unposted document', async () => {
    vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(definitiveBreakdown)

    const { container } = renderTotals({ isProforma: true, proformaTotals })

    await screen.findByText('Estimated total')
    expectNoForbiddenToken(container.innerHTML, 'the proforma totals box')
  })

  it('never asks the server for a tax breakdown on a proforma', async () => {
    vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(definitiveBreakdown)

    renderTotals({ isProforma: true, proformaTotals })

    await screen.findByText('Estimated total')
    expect(taxApi.fetchTaxBreakdown).not.toHaveBeenCalled()
  })

  it('shows the gross estimated total and no net subtotal', async () => {
    vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(definitiveBreakdown)

    renderTotals({ isProforma: true, proformaTotals })

    expect(await screen.findByText('Estimated total')).toBeInTheDocument()
    expect(screen.getByText(/238[.,]000/)).toBeInTheDocument()
    expect(screen.queryByText('Subtotal')).not.toBeInTheDocument()
    // 200.000 is the net basis; printing it next to 238.000 hands the reader
    // the subtraction that recovers the VAT.
    expect(screen.queryByText(/200[.,]000/)).not.toBeInTheDocument()
  })

  it('prints the duty and discount rows the box needs to close', async () => {
    vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(definitiveBreakdown)

    renderTotals({
      isProforma: true,
      proformaTotals: {
        ...proformaTotals,
        stamp_duty: '1.000',
        discount: '5.000',
      },
    })

    expect(await screen.findByText('Estimated total')).toBeInTheDocument()
    expect(screen.getByText('Stamp duty')).toBeInTheDocument()
    expect(screen.getByText('Discount')).toBeInTheDocument()
    expect(screen.getByText(/1[.,]000/)).toBeInTheDocument()
    expect(screen.getByText(/5[.,]000/)).toBeInTheDocument()
  })

  it('names an upward residual an adjustment, never a discount', async () => {
    vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(definitiveBreakdown)

    renderTotals({
      isProforma: true,
      proformaTotals: { ...proformaTotals, adjustment: '2.000' },
    })

    expect(await screen.findByText('Adjustment')).toBeInTheDocument()
    expect(screen.queryByText('Discount')).not.toBeInTheDocument()
  })

  it('leaves a definitive document exactly as it was', async () => {
    vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(definitiveBreakdown)

    renderTotals({})

    await waitFor(() => {
      expect(screen.getByText('Subtotal')).toBeInTheDocument()
    })
    expect(screen.getByText(/TVA 19/)).toBeInTheDocument()
    expect(screen.getByText('Total')).toBeInTheDocument()
    expect(taxApi.fetchTaxBreakdown).toHaveBeenCalledWith('doc-1')
  })
})
