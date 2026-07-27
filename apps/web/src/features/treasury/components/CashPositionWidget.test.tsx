import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { formatCurrency } from '@/lib/format'
import { CashPositionWidget } from './CashPositionWidget'

const mocks = vi.hoisted(() => ({
  canAccessModule: vi.fn(),
  hasModule: vi.fn(),
  useCashPosition: vi.fn(),
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, options?: { count?: number; days?: number }) => {
    if (options?.count !== undefined) return `${key}:${String(options.count)}`
    if (options?.days !== undefined) return `${key}:${String(options.days)}`
    return key
  } }),
}))

vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ canAccessModule: mocks.canAccessModule }),
}))

vi.mock('@/contexts/CompanyConfigContext', () => ({
  useCompanyConfig: () => ({ hasModule: mocks.hasModule }),
}))

vi.mock('../hooks/useCashPosition', () => ({
  useCashPosition: mocks.useCashPosition,
}))

const position = {
  as_of: '2026-07-12T12:00:00Z',
  currency: 'TND',
  grand_total: '1250.000',
  groups: [
    { type: 'cash_register', total: '250.000', repositories: [{ id: 'r1' }, { id: 'r2' }] },
    { type: 'bank_account', total: '900.000', repositories: [{ id: 'b1' }] },
    { type: 'safe', total: '100.000', repositories: [{ id: 's1' }, { id: 's2' }, { id: 's3' }] },
  ],
  groups_by_location: [
    { location_id: 'loc-a', location_name: 'Store A', total: '250.000' },
    { location_id: null, location_name: 'Unattributed', total: '1000.000' },
  ],
  flows: { window_days: 7, in: '400.000', out: '175.000' },
}

describe('CashPositionWidget', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.canAccessModule.mockReturnValue(true)
    mocks.hasModule.mockReturnValue(true)
    mocks.useCashPosition.mockReturnValue({ data: position, isLoading: false, isError: false })
  })

  function renderWidget() {
    return render(<MemoryRouter><CashPositionWidget /></MemoryRouter>)
  }

  it('returns null without treasury permission', () => {
    mocks.canAccessModule.mockReturnValue(false)

    const { container } = renderWidget()

    expect(container).toBeEmptyDOMElement()
    expect(mocks.useCashPosition).not.toHaveBeenCalled()
  })

  it('returns null when the company Treasury module is disabled', () => {
    mocks.hasModule.mockReturnValue(false)

    const { container } = renderWidget()

    expect(container).toBeEmptyDOMElement()
    expect(mocks.useCashPosition).not.toHaveBeenCalled()
  })

  it('renders the grand total, repository type counts, seven-day flows, and report links', () => {
    renderWidget()

    expect(mocks.useCashPosition).toHaveBeenCalledWith({ flowsWindow: 7 })
    expect(screen.getByText(/1[,.\s]?250/)).toBeInTheDocument()
    expect(screen.getByText('cashWidget.types.cashRegisters:2')).toBeInTheDocument()
    expect(screen.getByText('cashWidget.types.bankAccounts:1')).toBeInTheDocument()
    expect(screen.getByText('cashWidget.types.safes:3')).toBeInTheDocument()
    expect(screen.getAllByText(formatCurrency('250.000', { currency: 'TND' }))).toHaveLength(2)
    expect(screen.getByText(formatCurrency('900.000', { currency: 'TND' }))).toBeInTheDocument()
    expect(screen.getByText(formatCurrency('100.000', { currency: 'TND' }))).toBeInTheDocument()
    expect(screen.getByText('cashWidget.window:7')).toBeInTheDocument()
    expect(screen.getByText(/400/)).toBeInTheDocument()
    expect(screen.getByText(/175/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'cashWidget.viewOverview' })).toHaveAttribute('href', '/finance/overview')
    expect(screen.getByRole('link', { name: 'cashWidget.viewMovements' })).toHaveAttribute('href', '/finance/cash-movements')
  })

  it('renders the location breakdown in a separate labelled section', () => {
    renderWidget()

    expect(screen.getByText('cashWidget.byLocation')).toBeInTheDocument()
    expect(screen.getByText('Store A')).toBeInTheDocument()
    expect(screen.getByText('cashWidget.unattributed')).toBeInTheDocument()
  })
})
