import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { DocumentTotals } from './DocumentTotals'
import * as taxApi from '../api/taxApi'

// Mock the taxApi module
vi.mock('../api/taxApi')

// Mock translation hook
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'documents.subtotal': 'Subtotal',
        'documents.total': 'Total',
        'tax.breakdown.stampDuty': 'Stamp Duty',
        'tax.breakdown.noTaxes': 'No taxes applied',
        'tax.breakdown.error': 'Unable to load tax breakdown',
        'invoices.balanceDue': 'Balance Due',
      }
      return translations[key] || key
    },
  }),
}))

describe('DocumentTotals', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    })
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  const renderComponent = (props: any) => {
    return render(
      <QueryClientProvider client={queryClient}>
        <DocumentTotals {...props} />
      </QueryClientProvider>
    )
  }

  describe('Loading State', () => {
    it('should show loading skeleton', () => {
      vi.mocked(taxApi.fetchTaxBreakdown).mockImplementation(
        () => new Promise(() => {}) // Never resolves
      )

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'EUR',
      })

      const skeletons = document.querySelectorAll('.animate-pulse')
      expect(skeletons.length).toBeGreaterThan(0)
    })
  })

  describe('Error State', () => {
    it('should show error message when API fails', async () => {
      vi.mocked(taxApi.fetchTaxBreakdown).mockRejectedValue(
        new Error('API Error')
      )

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'EUR',
      })

      await waitFor(() => {
        expect(screen.getByText('Unable to load tax breakdown')).toBeInTheDocument()
      })
    })
  })

  describe('Tunisia Invoice (TND currency with stamp duty)', () => {
    it('should display all tax components with 3 decimal places', async () => {
      const mockBreakdown = {
        subtotal: '1000.000',
        discount: '0.000',
        line_tax_amount: '190.000',
        stamp_duty_amount: '1.000',
        total_tax_amount: '191.000',
        total: '1191.000',
        tax_details: [
          {
            tax_type: 'percentage' as const,
            tax_name: 'TVA',
            tax_rate: '19.0000',
            tax_base: '1000.000',
            tax_amount: '190.000',
          },
        ],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'TND',
      })

      await waitFor(() => {
        expect(screen.getByText('Subtotal')).toBeInTheDocument()
      })

      // Check subtotal with 3 decimals
      // W-7 F-7 (fix lane L4): the totals panel now formats against the
      // DOCUMENT currency, so TND renders fr-TN (U+202F group, comma decimal).
      // These assertions previously read '1,000.000 TND' — the en-US shape that
      // reads as one million to a French or Tunisian customer.
      expect(screen.getByText('1 000,000 TND')).toBeInTheDocument()

      // Check tax line with rate
      expect(screen.getByText(/TVA 19%/)).toBeInTheDocument()
      expect(screen.queryByText(/TVA 19\.0000%/)).not.toBeInTheDocument()
      expect(screen.getByText('190,000 TND')).toBeInTheDocument()

      // Check stamp duty
      expect(screen.getByText('Stamp Duty')).toBeInTheDocument()
      const stampDutyAmounts = screen.getAllByText(/1,000 TND/)
      expect(stampDutyAmounts.length).toBeGreaterThan(0)

      // Check total with 3 decimals
      expect(screen.getByText('Total')).toBeInTheDocument()
      expect(screen.getByText('1 191,000 TND')).toBeInTheDocument()
    })

    it('should not show stamp duty when amount is zero', async () => {
      const mockBreakdown = {
        subtotal: '1000.000',
        discount: '0.000',
        line_tax_amount: '190.000',
        stamp_duty_amount: '0.000',
        total_tax_amount: '190.000',
        total: '1190.000',
        tax_details: [
          {
            tax_type: 'percentage' as const,
            tax_name: 'TVA',
            tax_rate: '19',
            tax_base: '1000.000',
            tax_amount: '190.000',
          },
        ],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'TND',
      })

      await waitFor(() => {
        expect(screen.getByText('Subtotal')).toBeInTheDocument()
      })

      // Stamp duty should not be shown
      expect(screen.queryByText('Stamp Duty')).not.toBeInTheDocument()
    })
  })

  describe('France Invoice (EUR currency without stamp duty)', () => {
    it('should display taxes with 2 decimal places and no stamp duty', async () => {
      const mockBreakdown = {
        subtotal: '1000.00',
        discount: '0.00',
        line_tax_amount: '200.00',
        stamp_duty_amount: '0.00',
        total_tax_amount: '200.00',
        total: '1200.00',
        tax_details: [
          {
            tax_type: 'percentage' as const,
            tax_name: 'TVA',
            tax_rate: '20',
            tax_base: '1000.00',
            tax_amount: '200.00',
          },
        ],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'EUR',
      })

      await waitFor(() => {
        expect(screen.getByText('Subtotal')).toBeInTheDocument()
      })

      // Check EUR formatting with 2 decimals
      expect(screen.getByText('1 000,00 EUR')).toBeInTheDocument()
      expect(screen.getByText('200,00 EUR')).toBeInTheDocument()
      expect(screen.getByText('1 200,00 EUR')).toBeInTheDocument()

      // Stamp duty should not be shown
      expect(screen.queryByText('Stamp Duty')).not.toBeInTheDocument()
    })
  })

  describe('Multiple Tax Rates', () => {
    it('should display each tax rate on separate line', async () => {
      const mockBreakdown = {
        subtotal: '1000.000',
        discount: '0.000',
        line_tax_amount: '160.000',
        stamp_duty_amount: '0.000',
        total_tax_amount: '160.000',
        total: '1160.000',
        tax_details: [
          {
            tax_type: 'percentage' as const,
            tax_name: 'TVA',
            tax_rate: '7',
            tax_base: '500.000',
            tax_amount: '35.000',
          },
          {
            tax_type: 'percentage' as const,
            tax_name: 'TVA',
            tax_rate: '19',
            tax_base: '500.000',
            tax_amount: '125.000',
          },
        ],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'TND',
      })

      await waitFor(() => {
        expect(screen.getByText('Subtotal')).toBeInTheDocument()
      })

      // Both tax rates should be displayed
      expect(screen.getByText(/TVA 7%/)).toBeInTheDocument()
      expect(screen.getByText(/TVA 19%/)).toBeInTheDocument()
      expect(screen.getByText(/35,000 TND/)).toBeInTheDocument()
      expect(screen.getByText(/125,000 TND/)).toBeInTheDocument()
    })
  })

  describe('No Taxes Applied', () => {
    it('should show "No taxes applied" message', async () => {
      const mockBreakdown = {
        subtotal: '1000.00',
        discount: '0.00',
        line_tax_amount: '0.00',
        stamp_duty_amount: '0.00',
        total_tax_amount: '0.00',
        total: '1000.00',
        tax_details: [],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'quote',
        currency: 'EUR',
      })

      await waitFor(() => {
        expect(screen.getByText('No taxes applied')).toBeInTheDocument()
      })
    })
  })

  describe('Balance Due (Posted Invoices)', () => {
    it('should show balance due when enabled', async () => {
      const mockBreakdown = {
        subtotal: '1000.00',
        discount: '0.00',
        line_tax_amount: '200.00',
        stamp_duty_amount: '0.00',
        total_tax_amount: '200.00',
        total: '1200.00',
        tax_details: [
          {
            tax_type: 'percentage' as const,
            tax_name: 'TVA',
            tax_rate: '20',
            tax_base: '1000.00',
            tax_amount: '200.00',
          },
        ],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'EUR',
        showBalanceDue: true,
        balanceDue: 1200.0,
      })

      await waitFor(() => {
        expect(screen.getByText('Balance Due')).toBeInTheDocument()
      })

      // Balance due amount appears, check it exists (may appear multiple times with total)
      const amounts = screen.getAllByText(/1 200,00 EUR/)
      expect(amounts.length).toBeGreaterThan(0)
    })

    it('should not show balance due when disabled', async () => {
      const mockBreakdown = {
        subtotal: '1000.00',
        discount: '0.00',
        line_tax_amount: '200.00',
        stamp_duty_amount: '0.00',
        total_tax_amount: '200.00',
        total: '1200.00',
        tax_details: [],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'EUR',
        showBalanceDue: false,
      })

      await waitFor(() => {
        expect(screen.getByText('Subtotal')).toBeInTheDocument()
      })

      expect(screen.queryByText('Balance Due')).not.toBeInTheDocument()
    })

    it('should not show balance due when amount is zero', async () => {
      const mockBreakdown = {
        subtotal: '1000.00',
        discount: '0.00',
        line_tax_amount: '200.00',
        stamp_duty_amount: '0.00',
        total_tax_amount: '200.00',
        total: '1200.00',
        tax_details: [],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'EUR',
        showBalanceDue: true,
        balanceDue: 0,
      })

      await waitFor(() => {
        expect(screen.getByText('Subtotal')).toBeInTheDocument()
      })

      expect(screen.queryByText('Balance Due')).not.toBeInTheDocument()
    })
  })

  describe('Fixed Amount Taxes', () => {
    it('should display fixed amount tax without percentage', async () => {
      const mockBreakdown = {
        subtotal: '1000.00',
        discount: '0.00',
        line_tax_amount: '50.00',
        stamp_duty_amount: '0.00',
        total_tax_amount: '50.00',
        total: '1050.00',
        tax_details: [
          {
            tax_type: 'fixed_amount' as const,
            tax_name: 'Eco Tax',
            tax_rate: null,
            tax_base: '1000.00',
            tax_amount: '50.00',
          },
        ],
      }

      vi.mocked(taxApi.fetchTaxBreakdown).mockResolvedValue(mockBreakdown)

      renderComponent({
        documentId: 'test-id',
        documentType: 'invoice',
        currency: 'EUR',
      })

      await waitFor(() => {
        expect(screen.getByText('Eco Tax')).toBeInTheDocument()
      })

      // Should show tax name without percentage
      expect(screen.getByText(/Eco Tax/)).toBeInTheDocument()
      expect(screen.queryByText(/%/)).not.toBeInTheDocument()
    })
  })
})
