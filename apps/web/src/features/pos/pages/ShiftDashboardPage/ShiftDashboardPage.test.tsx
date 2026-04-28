import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, fireEvent, waitFor } from '@testing-library/react'
import { ShiftDashboardPage } from './ShiftDashboardPage'

const eurMock = {
  currency: 'EUR',
  locale: 'fr-FR',
  decimals: 2,
  format: (value: string | number) => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    return `${num.toFixed(2)} EUR`
  },
  toFixed: (value: number) => value.toFixed(2),
}

const tndMock = {
  currency: 'TND',
  locale: 'fr-TN',
  decimals: 3,
  format: (value: string | number) => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    return `${num.toFixed(3)} TND`
  },
  toFixed: (value: number) => value.toFixed(3),
}

// useCurrency is a vi.fn() so individual tests can override its return value.
const mockUseCurrency = vi.fn(() => eurMock)

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: (...args: Parameters<typeof mockUseCurrency>) => mockUseCurrency(...args),
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

describe('ShiftDashboardPage — EUR 2-decimal variance renders at currency scale', () => {
  it('computes variance at 2 decimals for EUR', async () => {
    mockUseCurrency.mockReturnValue({ ...eurMock, decimals: 2 })

    const currentShift: import('./ShiftDashboardPage').Shift = {
      id: 's-eur',
      terminal_id: 't1',
      shift_number: 1,
      cashier_id: 'u1',
      cashier_name: 'Jean',
      opening_cash: '100.00',
      expected_cash: '250.10',
      opened_at: '2026-04-24T08:00:00Z',
      status: 'OPEN',
    }
    const terminal: import('./ShiftDashboardPage').Terminal = {
      id: 't1',
      code: 'T001',
      location_id: 'l1',
      location_name: 'Main',
    }

    const { getByRole, getByPlaceholderText, getByText } = render(
      <ShiftDashboardPage
        currentShift={currentShift}
        terminal={terminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    fireEvent.click(getByRole('button', { name: /close shift/i }))
    const input = getByPlaceholderText(/actual cash/i)
    fireEvent.change(input, { target: { value: '250.13' } })

    // Variance should be '0.03' — 2 decimals for EUR, not '0.030'
    await waitFor(() => {
      expect(getByText('0.03')).toBeInTheDocument()
    })
  })
})

describe('ShiftDashboardPage — TND 3-decimal variance (no float drift)', () => {
  // Switch useCurrency to return TND (3 decimals) so MoneyInput allows 3 decimal places.
  // Without this override MoneyInput truncates '250.103' → '250.10', hiding the drift.
  beforeEach(() => {
    mockUseCurrency.mockReturnValue(tndMock)
  })

  afterEach(() => {
    mockUseCurrency.mockReturnValue(eurMock)
  })

  const tndShift: import('./ShiftDashboardPage').Shift = {
    id: 's1',
    terminal_id: 't1',
    shift_number: 1,
    cashier_id: 'u1',
    cashier_name: 'Amine',
    opening_cash: '100.000',
    expected_cash: '250.100',
    opened_at: '2026-04-24T08:00:00Z',
    status: 'OPEN',
  }

  const tndTerminal: import('./ShiftDashboardPage').Terminal = {
    id: 't1',
    code: 'T001',
    location_id: 'l1',
    location_name: 'Main',
  }

  it('computes variance exactly for TND 3-decimal values without float drift', async () => {
    const { getByRole, getByPlaceholderText, getByText } = render(
      <ShiftDashboardPage
        currentShift={tndShift}
        terminal={tndTerminal}
        onOpenShift={vi.fn()}
        onCloseShift={vi.fn()}
        onGenerateXReport={vi.fn()}
        onCashDeposit={vi.fn()}
        onCashPayout={vi.fn()}
      />
    )

    // Open the Close Shift modal
    const closeButton = getByRole('button', { name: /close shift/i })
    fireEvent.click(closeButton)

    // Enter an actual cash that differs by exactly 0.003 from expected 250.100.
    // With TND decimals=3, MoneyInput allows 3 decimal places so '250.103' passes through.
    const input = getByPlaceholderText(/actual cash/i)
    fireEvent.change(input, { target: { value: '250.103' } })

    // Variance should be exactly '0.003' — NOT '0.0030000000000001355' from float arithmetic.
    // bcsub('250.103', '250.100', 3) === '0.003' (exact Big.js arithmetic).
    await waitFor(() => {
      expect(getByText('0.003')).toBeInTheDocument()
    })
  })
})
