import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { formatCurrency } from '@/lib/format'
import { getCurrentMonthStartInputValue, getTodayDateInputValue } from './reportPageUtils'

import { CashMovementsReportPage } from './CashMovementsReportPage'

const mockUseCashMovementsReport = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../hooks/useCashMovementsReport', () => ({
  useCashMovementsReport: mockUseCashMovementsReport,
}))

vi.mock('@/features/treasury/hooks/usePaymentRepositories', () => ({
  usePaymentRepositories: () => ({
    data: [
      {
        id: '11111111-1111-4111-8111-111111111111',
        code: 'CASH-01',
        name: 'Main till',
        type: 'cash_register',
        is_active: true,
        is_default: true,
        balance: '100.000',
        currency: 'TND',
      },
      {
        id: '22222222-2222-4222-8222-222222222222',
        code: 'OLD',
        name: 'Inactive safe',
        type: 'safe',
        is_active: false,
        is_default: false,
        balance: '0.000',
        currency: 'TND',
      },
    ],
    isLoading: false,
  }),
}))

const paymentId = '33333333-3333-4333-8333-333333333333'
const journalSourceId = '44444444-4444-4444-8444-444444444444'

const report = {
  data: [
    {
      date: '2026-07-12',
      direction: 'in' as const,
      amount: '125.000',
      currency: 'TND',
      source_type: 'payment',
      source_id: paymentId,
      counterparty: 'Client A',
      gl_account: '531100',
    },
    {
      date: '2026-07-11',
      direction: 'out' as const,
      amount: '10.50',
      currency: 'EUR',
      source_type: 'expense',
      source_id: journalSourceId,
      counterparty: null,
      gl_account: '512000',
    },
  ],
  meta: {
    current_page: 1,
    per_page: 50,
    total: 51,
    last_page: 2,
    from: 1,
    to: 50,
    totals: {
      TND: { in: '125.000', out: '25.000', net: '100.000' },
      EUR: { in: '20.50', out: '10.50', net: '10.00' },
    },
  },
}

function renderPage() {
  return render(
    <MemoryRouter>
      <CashMovementsReportPage />
    </MemoryRouter>,
  )
}

describe('CashMovementsReportPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseCashMovementsReport.mockReturnValue({
      data: report,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })
  })

  it('defaults to the current month and applies repository and direction filters', async () => {
    const user = userEvent.setup()
    renderPage()

    expect(mockUseCashMovementsReport).toHaveBeenLastCalledWith({
      from: getCurrentMonthStartInputValue(),
      to: getTodayDateInputValue(),
      page: 1,
    })

    const repository = screen.getByRole('combobox', {
      name: 'finance:cashMovements.filters.repository',
    })
    const direction = screen.getByRole('combobox', {
      name: 'finance:cashMovements.filters.direction',
    })
    expect(within(repository).getByRole('option', { name: /Inactive safe/ })).toBeInTheDocument()

    await user.selectOptions(repository, '11111111-1111-4111-8111-111111111111')
    await user.selectOptions(direction, 'out')

    expect(mockUseCashMovementsReport).toHaveBeenLastCalledWith({
      from: getCurrentMonthStartInputValue(),
      to: getTodayDateInputValue(),
      repository_id: '11111111-1111-4111-8111-111111111111',
      direction: 'out',
      page: 1,
    })
  })

  it('renders a separate formatted totals row for every currency', () => {
    renderPage()

    const tndRow = screen.getByTestId('cash-movements-total-TND')
    expect(tndRow).toHaveTextContent(formatCurrency('125.000', { currency: 'TND' }))
    expect(tndRow).toHaveTextContent(formatCurrency('25.000', { currency: 'TND' }))
    expect(tndRow).toHaveTextContent(formatCurrency('100.000', { currency: 'TND' }))

    const eurRow = screen.getByTestId('cash-movements-total-EUR')
    expect(eurRow).toHaveTextContent(formatCurrency('20.50', { currency: 'EUR' }))
    expect(eurRow).toHaveTextContent(formatCurrency('10.50', { currency: 'EUR' }))
    expect(eurRow).toHaveTextContent(formatCurrency('10.00', { currency: 'EUR' }))
  })

  it('renders the manual table with a safe payment link and copy fallback', async () => {
    const user = userEvent.setup()
    const clipboardSpy = vi.spyOn(navigator.clipboard, 'writeText')
    renderPage()

    expect(screen.getByRole('columnheader', { name: 'finance:cashMovements.columns.date' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'finance:cashMovements.columns.glAccount' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: paymentId })).toHaveAttribute(
      'href',
      `/treasury/payments/${paymentId}`,
    )
    expect(screen.queryByRole('link', { name: journalSourceId })).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', {
      name: 'finance:cashMovements.copySourceRef',
    }))
    expect(clipboardSpy).toHaveBeenCalledWith(journalSourceId)
  })

  it('moves through server pagination without changing the filters', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('button', { name: 'pagination.next' }))

    expect(mockUseCashMovementsReport).toHaveBeenLastCalledWith({
      from: getCurrentMonthStartInputValue(),
      to: getTodayDateInputValue(),
      page: 2,
    })
  })
})
