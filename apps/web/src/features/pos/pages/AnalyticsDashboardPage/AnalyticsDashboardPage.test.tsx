import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { AnalyticsDashboardPage } from './AnalyticsDashboardPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key.split('.').pop() ?? key,
  }),
}))

vi.mock('@/contexts', () => ({
  useCompanyConfig: () => ({ config: { vertical: 'retail' } }),
}))

vi.mock('echarts-for-react', () => ({
  __esModule: true,
  default: () => <div data-testid="echarts-mock" />,
}))

const mockSummary = {
  data: {
    receipt_count: 42,
    gross_sales: '5000.00',
    net_sales: '4200.00',
    tax_total: '800.00',
    average_ticket: '100.00',
    refund_count: 2,
    refund_total: '150.00',
    voided_count: 1,
    payment_breakdown: [
      { payment_type: 'cash', total: '3000.00', count: 25 },
      { payment_type: 'card', total: '1200.00', count: 17 },
    ],
  },
  isLoading: false,
  isError: false,
}

const mockEmpty = { data: undefined, isLoading: false, isError: false }

vi.mock('../../hooks/useAnalytics', () => ({
  useSalesSummary: () => mockSummary,
  useSalesByCategory: () => mockEmpty,
  useSalesByProduct: () => mockEmpty,
  useSalesByPeriod: () => ({ data: [], isLoading: false, isError: false }),
  useCashierPerformance: () => mockEmpty,
  useDiscountAnalysis: () => mockEmpty,
  useCustomerAnalytics: () => mockEmpty,
  useFnbMetrics: () => mockEmpty,
}))

describe('AnalyticsDashboardPage', () => {
  it('renders the page title and tabs', () => {
    const { getByText } = render(<AnalyticsDashboardPage />)

    expect(getByText('title')).toBeInTheDocument()
    expect(getByText('summary')).toBeInTheDocument()
    expect(getByText('products')).toBeInTheDocument()
    expect(getByText('cashiers')).toBeInTheDocument()
    expect(getByText('discounts')).toBeInTheDocument()
    expect(getByText('customers')).toBeInTheDocument()
  })

  it('renders summary cards with data', () => {
    const { getByText } = render(<AnalyticsDashboardPage />)

    expect(getByText('42')).toBeInTheDocument()
    expect(getByText('5,000.00')).toBeInTheDocument()
  })

  it('switches tabs on click', () => {
    const { getByText } = render(<AnalyticsDashboardPage />)

    const productsTab = getByText('products')
    fireEvent.click(productsTab)

    // After switching to products tab, summary cards should not be visible
    // The products tab content should render (even if empty)
    // active tab uses the brand border token (borderColors.primary → blue-500)
    expect(productsTab.className).toContain('blue-500')
  })

  it('renders date filter presets', () => {
    const { getByText } = render(<AnalyticsDashboardPage />)

    expect(getByText('today')).toBeInTheDocument()
    expect(getByText('thisWeek')).toBeInTheDocument()
    expect(getByText('thisMonth')).toBeInTheDocument()
    expect(getByText('last30Days')).toBeInTheDocument()
  })

  it('changes date filter when preset is clicked', () => {
    const { getByText } = render(<AnalyticsDashboardPage />)

    const todayBtn = getByText('today')
    fireEvent.click(todayBtn)

    // active preset uses the brand fill token (colors.primary[600] → blue-600)
    expect(todayBtn.className).toContain('blue-600')
  })
})
