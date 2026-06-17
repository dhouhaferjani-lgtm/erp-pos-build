import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { OpenShiftModal } from './OpenShiftModal'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('../api/shiftApi', () => ({
  openShift: vi.fn(),
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

describe('OpenShiftModal (color-drift)', () => {
  const defaultProps = {
    isOpen: true,
    onClose: vi.fn(),
    terminalId: 'POS01',
    onSuccess: vi.fn(),
  }

  it('renders the opening-cash instructions and input', () => {
    renderWithClient(<OpenShiftModal {...defaultProps} />)

    expect(screen.getByText('common:pos.enterOpeningCash')).toBeInTheDocument()
    expect(screen.getByText('common:pos.openingCashHelp')).toBeInTheDocument()
    expect(screen.getByText('common:pos.openingCash')).toBeInTheDocument()
  })

  it('renders the must-open-shift warning', () => {
    renderWithClient(<OpenShiftModal {...defaultProps} />)

    expect(
      screen.getByText('common:pos.mustOpenShiftWarning')
    ).toBeInTheDocument()
  })

  it('renders cancel and start-shift actions', () => {
    renderWithClient(<OpenShiftModal {...defaultProps} />)

    expect(
      screen.getByRole('button', { name: 'common:cancel' })
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'common:pos.startShift' })
    ).toBeInTheDocument()
  })
})
