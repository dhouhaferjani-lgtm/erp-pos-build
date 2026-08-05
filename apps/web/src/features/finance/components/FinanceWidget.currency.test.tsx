import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { useCompanyStore, type Company } from '@/stores/companyStore'
import { FinanceWidget } from './FinanceWidget'

vi.mock('../hooks/useFinanceSummary', () => ({
  useFinanceSummary: () => ({
    data: {
      total_assets: '228728.386',
      total_liabilities: '1000.500',
      net_income_mtd: '250.750',
      net_income_ytd: '-125.250',
      accounts_receivable: '900.000',
      accounts_payable: '210.000',
    },
    isLoading: false,
  }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: React.ReactNode }) => <a href="#">{children}</a>,
}))

/** fr-TN groups with U+202F (narrow no-break space). */
const NNBSP = ' '

function seedCompany(currency: string, locale: string): void {
  const company: Company = {
    id: 'co-1',
    name: 'PharmaBio Tunisie SARL',
    legalName: 'PharmaBio Tunisie SARL',
    taxId: null,
    countryCode: currency === 'TND' ? 'TN' : 'FR',
    currency,
    locale,
    timezone: 'Africa/Tunis',
  }
  useCompanyStore.setState({ currentCompanyId: company.id, companies: [company] })
}

/**
 * W-6 D6 (fix lane L4): every FinanceWidget tile called `formatCurrency(value)`
 * with NO options, and `formatCurrency` defaulted the currency to `'EUR'`. On the
 * Tunisian `PharmaBio Tunisie SARL` company the six tiles therefore rendered
 * `228 728,39 EUR` — wrong symbol AND wrong scale — beside four sibling
 * StatCards on the same `/finance/overview` viewport rendering `228 728,386 TND`.
 */
describe('FinanceWidget currency rendering', () => {
  beforeEach(() => {
    seedCompany('TND', 'fr_TN')
  })

  afterEach(() => {
    useCompanyStore.setState({ currentCompanyId: null, companies: [] })
  })

  it('renders every tile in the company currency at the company scale', () => {
    render(<FinanceWidget />)

    expect(screen.getByText(`228${NNBSP}728,386 TND`)).toBeInTheDocument()
    expect(screen.getByText(`1${NNBSP}000,500 TND`)).toBeInTheDocument()
    expect(screen.getByText('250,750 TND')).toBeInTheDocument()
    expect(screen.getByText('-125,250 TND')).toBeInTheDocument()
    expect(screen.getByText('900,000 TND')).toBeInTheDocument()
    expect(screen.getByText('210,000 TND')).toBeInTheDocument()
  })

  it('never labels a Tunisian company`s money as EUR', () => {
    const { container } = render(<FinanceWidget />)

    expect(container.textContent).not.toContain('EUR')
  })

  it('re-scales to 2 decimals for a EUR company', () => {
    seedCompany('EUR', 'fr_FR')

    render(<FinanceWidget />)

    expect(screen.getByText(`228${NNBSP}728,39 EUR`)).toBeInTheDocument()
  })
})
