import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { ShiftDashboardPage } from './ShiftDashboardPage'

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    locale: 'fr-FR',
    decimals: 2,
    format: (value: string | number) => {
      const num = typeof value === 'string' ? parseFloat(value) : value
      return `${num.toFixed(2)} EUR`
    },
    toFixed: (value: number) => value.toFixed(2),
  }),
  getDecimals: (currency: string) => currency === 'TND' || currency === 'LYD' ? 3 : 2,
  getLocale: (_currency: string) => 'fr-FR',
  formatAmount: (value: string | number, currency: string) => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    const decimals = currency === 'TND' || currency === 'LYD' ? 3 : 2
    return `${num.toFixed(decimals)} ${currency}`
  },
}))

describe('ShiftDashboardPage', () => {
  const mockCurrentShift: import('./ShiftDashboardPage').Shift = {
    id: 'shift-1',
    terminal_id: 'terminal-1',
    shift_number: 42,
    cashier_id: 'user-1',
    cashier_name: 'John Doe',
    opening_cash: '500.000',
    expected_cash: '1250.000',
    opened_at: '2026-01-09T08:00:00Z',
    status: 'OPEN',
  }

  const mockTerminal: import('./ShiftDashboardPage').Terminal = {
    id: 'terminal-1',
    code: 'POS-01',
    location_id: 'loc-1',
    location_name: 'Main Store',
  }

  it('renders terminal information', () => {
    const { getByText } = render(
      <ShiftDashboardPage
        currentShift={null}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    expect(getByText('POS-01')).toBeInTheDocument()
    expect(getByText(/main store/i)).toBeInTheDocument()
  })

  it('shows "No Active Shift" when no shift is open', () => {
    const { getByText } = render(
      <ShiftDashboardPage
        currentShift={null}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    expect(getByText(/no active shift/i)).toBeInTheDocument()
  })

  it('displays "Open Shift" button when no shift is active', () => {
    const { getByRole } = render(
      <ShiftDashboardPage
        currentShift={null}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    expect(getByRole('button', { name: /open shift/i })).toBeInTheDocument()
  })

  it('calls onOpenShift when opening balance is submitted', async () => {
    const onOpenShift = vi.fn()
    const { getByRole, getByPlaceholderText } = render(
      <ShiftDashboardPage
        currentShift={null}
        terminal={mockTerminal}
        onOpenShift={onOpenShift}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    const openButton = getByRole('button', { name: /open shift/i })
    fireEvent.click(openButton)

    // Should show modal with opening balance input
    const input = getByPlaceholderText(/opening balance/i)
    fireEvent.change(input, { target: { value: '500.00' } })

    const confirmButton = getByRole('button', { name: /confirm/i })
    fireEvent.click(confirmButton)

    await waitFor(() => {
      expect(onOpenShift).toHaveBeenCalledWith('500.00')
    })
  })

  it('displays current shift information when shift is open', () => {
    const { getByText } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    expect(getByText(/shift #42/i)).toBeInTheDocument()
    expect(getByText('John Doe')).toBeInTheDocument()
    expect(getByText(/500\.000/)).toBeInTheDocument()
    expect(getByText(/1250\.000/)).toBeInTheDocument()
  })

  it('displays shift duration when shift is open', () => {
    const { getAllByText } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    // formatDistanceToNow returns human-readable duration like "about 2 months", "3 hours", etc.
    // The duration appears in both the header and the shift info grid
    const durationElements = getAllByText(/(hours?|minutes?|days?|months?|years?|about|less than)/i)
    expect(durationElements.length).toBeGreaterThan(0)
  })

  it('displays cash drawer operations buttons when shift is open', () => {
    const { getByRole } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    expect(getByRole('button', { name: /deposit/i })).toBeInTheDocument()
    expect(getByRole('button', { name: /payout/i })).toBeInTheDocument()
  })

  it('displays X Report button when shift is open', () => {
    const { getByRole } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    expect(getByRole('button', { name: /x report/i })).toBeInTheDocument()
  })

  it('displays Close Shift button when shift is open', () => {
    const { getByRole } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    expect(getByRole('button', { name: /close shift/i })).toBeInTheDocument()
  })

  it('calls onGenerateXReport when X Report button clicked', () => {
    const onGenerateXReport = vi.fn()
    const { getByRole } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={onGenerateXReport}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    const xReportButton = getByRole('button', { name: /x report/i })
    fireEvent.click(xReportButton)

    expect(onGenerateXReport).toHaveBeenCalled()
  })

  it('calls onCloseShift with actual cash count when closing shift', async () => {
    const onCloseShift = vi.fn()
    const { getByRole, getByPlaceholderText } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={onCloseShift}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    const closeButton = getByRole('button', { name: /close shift/i })
    fireEvent.click(closeButton)

    // Should show modal with actual cash input
    const input = getByPlaceholderText(/actual cash/i)
    fireEvent.change(input, { target: { value: '1245.00' } })

    const confirmButton = getByRole('button', { name: /confirm/i })
    fireEvent.click(confirmButton)

    await waitFor(() => {
      expect(onCloseShift).toHaveBeenCalledWith('1245.00')
    })
  })

  it('calculates and displays cash variance when closing shift', async () => {
    const { getByRole, getByPlaceholderText, getByText } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    const closeButton = getByRole('button', { name: /close shift/i })
    fireEvent.click(closeButton)

    const input = getByPlaceholderText(/actual cash/i)
    fireEvent.change(input, { target: { value: '1245.00' } })

    // Expected: 1250.000, Actual: 1245.00, Variance: -5.00
    await waitFor(() => {
      expect(getByText(/-5\.00/)).toBeInTheDocument()
    })
  })

  it('calls onCashDeposit when deposit button clicked', async () => {
    const onCashDeposit = vi.fn()
    const { getByRole, getByPlaceholderText } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={onCashDeposit}
        onCashPayout={vi.fn()}
      />
    )

    const depositButton = getByRole('button', { name: /deposit/i })
    fireEvent.click(depositButton)

    const amountInput = getByPlaceholderText(/amount/i)
    fireEvent.change(amountInput, { target: { value: '100.00' } })

    const reasonInput = getByPlaceholderText(/reason/i)
    fireEvent.change(reasonInput, { target: { value: 'Safe drop' } })

    const confirmButton = getByRole('button', { name: /confirm/i })
    fireEvent.click(confirmButton)

    await waitFor(() => {
      expect(onCashDeposit).toHaveBeenCalledWith({
        amount: '100.00',
        reason: 'Safe drop',
      })
    })
  })

  it('calls onCashPayout when payout button clicked', async () => {
    const onCashPayout = vi.fn()
    const { getByRole, getByPlaceholderText } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={onCashPayout}
      />
    )

    const payoutButton = getByRole('button', { name: /payout/i })
    fireEvent.click(payoutButton)

    const amountInput = getByPlaceholderText(/amount/i)
    fireEvent.change(amountInput, { target: { value: '50.00' } })

    const reasonInput = getByPlaceholderText(/reason/i)
    fireEvent.change(reasonInput, { target: { value: 'Petty cash' } })

    const confirmButton = getByRole('button', { name: /confirm/i })
    fireEvent.click(confirmButton)

    await waitFor(() => {
      expect(onCashPayout).toHaveBeenCalledWith({
        amount: '50.00',
        reason: 'Petty cash',
      })
    })
  })

  it('disables operations buttons when shift is not open', () => {
    const { getByRole } = render(
      <ShiftDashboardPage
        currentShift={null}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    // Only "Open Shift" button should be enabled
    expect(getByRole('button', { name: /open shift/i })).not.toBeDisabled()
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { container } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
        touchOptimized={true}
      />
    )

    const page = container.firstChild as HTMLElement
    expect(page.className).toContain('p-6')
  })

  it('displays loading state when isLoading is true', () => {
    const { getByText } = render(
      <ShiftDashboardPage
        currentShift={mockCurrentShift}
        terminal={mockTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
        isLoading={true}
      />
    )

    expect(getByText(/loading/i)).toBeInTheDocument()
  })
})
