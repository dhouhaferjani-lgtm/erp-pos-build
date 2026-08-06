import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { FinanceWidget } from './FinanceWidget'

const mockRefetch = vi.fn()

vi.mock('../hooks/useFinanceSummary', () => ({
  useFinanceSummary: () => ({
    data: undefined,
    isLoading: false,
    error: { response: { status: 403, data: { error: { message: 'Forbidden' } } } },
    refetch: mockRefetch,
  }),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: React.ReactNode }) => <a href="#">{children}</a>,
}))

/**
 * Gate finding I-4 (2026-08-06 review): a manager can open /finance/overview
 * (route gate is reports.operational) but lacks reports.financial, so
 * GET /reports/finance-summary 403s. Before this fix the widget rendered six
 * fabricated `0.000` tiles from `data?.x ?? '0'` as if they were real
 * balances. It must render the error state instead — never zeros on error.
 */
describe('FinanceWidget error state', () => {
  it('renders the error state on a 403, not fabricated zero tiles', () => {
    const { container } = render(<FinanceWidget />)

    expect(screen.getByText('finance:widget.loadError')).toBeInTheDocument()
    expect(container.textContent).not.toContain('0.000')
    expect(container.textContent).not.toContain('finance:widget.totalAssets')
  })

  it('offers a retry that calls refetch', () => {
    render(<FinanceWidget />)

    screen.getByText('actions.tryAgain').click()

    expect(mockRefetch).toHaveBeenCalled()
  })
})
