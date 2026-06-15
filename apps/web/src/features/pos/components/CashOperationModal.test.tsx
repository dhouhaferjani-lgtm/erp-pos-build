import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CashOperationModal } from './CashOperationModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('../api/shiftApi', () => ({
  recordCashDeposit: vi.fn(),
  recordCashPayout: vi.fn(),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ currency: 'EUR', decimals: 2 }),
}))

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>
  )
}

describe('CashOperationModal (color-drift)', () => {
  const baseProps = {
    isOpen: true,
    onClose: vi.fn(),
    shiftId: 'shift-1',
    terminalCode: 'POS01',
  }

  it('renders deposit info, amount, reason and warning', () => {
    renderWithClient(<CashOperationModal {...baseProps} type="deposit" />)

    expect(screen.getByText('common:pos.depositInfo')).toBeInTheDocument()
    expect(screen.getByText('common:pos.amount')).toBeInTheDocument()
    expect(screen.getByText(/common:pos.reason/)).toBeInTheDocument()
    expect(
      screen.getByText('common:pos.cashOperationWarning')
    ).toBeInTheDocument()
  })

  it('renders the payout variant info text', () => {
    renderWithClient(<CashOperationModal {...baseProps} type="payout" />)

    expect(screen.getByText('common:pos.payoutInfo')).toBeInTheDocument()
  })

  it('renders cancel and record actions', () => {
    renderWithClient(<CashOperationModal {...baseProps} type="deposit" />)

    expect(
      screen.getByRole('button', { name: 'common:cancel' })
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'common:pos.recordDeposit' })
    ).toBeInTheDocument()
  })
})
