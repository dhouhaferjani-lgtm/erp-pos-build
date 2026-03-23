import { render, screen } from '@testing-library/react'
import { VatBreakdownTable } from '../VatBreakdownTable'
import type { VatRateBreakdown } from '../../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'finance:vatReporting.columns.rate': 'Rate (%)',
        'finance:vatReporting.columns.baseAmount': 'Base (HT)',
        'finance:vatReporting.columns.vatAmount': 'VAT Amount',
        'finance:vatReporting.columns.documentCount': 'Documents',
        'finance:vatReporting.columns.recoverable': 'Recoverable',
        'finance:vatReporting.total': 'Total',
      }
      return translations[key] ?? key
    },
  }),
}))

const mockBreakdowns: VatRateBreakdown[] = [
  {
    tax_rate: '20.00',
    base_amount: '10000.00',
    vat_amount: '2000.00',
    document_count: 15,
    is_recoverable: true,
  },
  {
    tax_rate: '7.00',
    base_amount: '5000.00',
    vat_amount: '350.00',
    document_count: 8,
    is_recoverable: false,
  },
]

describe('VatBreakdownTable', () => {
  it('renders rate rows with correct amounts', () => {
    render(<VatBreakdownTable breakdowns={mockBreakdowns} />)
    expect(screen.getByText('20.00')).toBeInTheDocument()
    expect(screen.getByText('7.00')).toBeInTheDocument()
    expect(screen.getByText('10,000.00')).toBeInTheDocument()
    expect(screen.getByText('2,000.00')).toBeInTheDocument()
    expect(screen.getByText('5,000.00')).toBeInTheDocument()
    expect(screen.getByText('350.00')).toBeInTheDocument()
  })

  it('shows total row', () => {
    render(<VatBreakdownTable breakdowns={mockBreakdowns} />)
    expect(screen.getByText('Total')).toBeInTheDocument()
    // Total base: 10000 + 5000 = 15000
    expect(screen.getByText('15,000.00')).toBeInTheDocument()
    // Total VAT: 2000 + 350 = 2350
    expect(screen.getByText('2,350.00')).toBeInTheDocument()
  })

  it('shows recoverable column when showRecoverable=true', () => {
    render(<VatBreakdownTable breakdowns={mockBreakdowns} showRecoverable />)
    expect(screen.getByText('Recoverable')).toBeInTheDocument()
  })

  it('hides recoverable column when showRecoverable is not set', () => {
    render(<VatBreakdownTable breakdowns={mockBreakdowns} />)
    expect(screen.queryByText('Recoverable')).not.toBeInTheDocument()
  })
})
