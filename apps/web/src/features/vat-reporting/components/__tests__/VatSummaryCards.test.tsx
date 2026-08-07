import { render, screen } from '@testing-library/react'
import { VatSummaryCards } from '../VatSummaryCards'
import { useCompanyStore } from '@/stores/companyStore'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'finance:vatReporting.summary.outputVat': 'Output VAT',
        'finance:vatReporting.summary.inputVat': 'Input VAT',
        'finance:vatReporting.summary.creditBroughtForward': 'Credit B/F',
        'finance:vatReporting.summary.amountPayable': 'Amount Payable',
      }
      return translations[key] ?? key
    },
  }),
}))

describe('VatSummaryCards', () => {
  // m-6 (2026-08-06 gate): amounts render through the currency-aware
  // `useCurrency().format()` path, which keys locale/decimals off the
  // ACTIVE COMPANY's currency. Pin a USD company (locale en-US, 2
  // decimals) so the expected strings below stay unambiguous.
  beforeEach(() => {
    useCompanyStore.setState({
      currentCompanyId: 'c1',
      companies: [
        {
          id: 'c1',
          name: 'Test Co',
          legalName: 'Test Co LLC',
          taxId: null,
          countryCode: 'US',
          currency: 'USD',
          locale: 'en-US',
          timezone: 'America/New_York',
        },
      ],
      isLoading: false,
    })
  })

  afterEach(() => {
    useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
  })

  it('renders 4 cards with provided amounts', () => {
    render(
      <VatSummaryCards
        outputVat="5000.00"
        inputVat="2000.00"
        creditBroughtForward="500.00"
        amountPayable="2500.00"
      />
    )

    expect(screen.getByText('Output VAT')).toBeInTheDocument()
    expect(screen.getByText('Input VAT')).toBeInTheDocument()
    expect(screen.getByText('Credit B/F')).toBeInTheDocument()
    expect(screen.getByText('Amount Payable')).toBeInTheDocument()
  })

  it('formats currency correctly', () => {
    render(
      <VatSummaryCards
        outputVat="5000.50"
        inputVat="2000.75"
        creditBroughtForward="500.00"
        amountPayable="2499.75"
      />
    )

    expect(screen.getByText('5,000.50')).toBeInTheDocument()
    expect(screen.getByText('2,000.75')).toBeInTheDocument()
    expect(screen.getByText('500.00')).toBeInTheDocument()
    expect(screen.getByText('2,499.75')).toBeInTheDocument()
  })
})
