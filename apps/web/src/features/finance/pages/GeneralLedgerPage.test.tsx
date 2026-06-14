import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { LedgerLine } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

const useLedgerMock = vi.fn()
vi.mock('../hooks/useLedger', () => ({
  useLedger: () => useLedgerMock() as unknown,
}))

const useAccountsMock = vi.fn()
vi.mock('../hooks/useAccounts', () => ({
  useAccounts: () => useAccountsMock() as unknown,
}))

vi.mock('@/components/QueryError', () => ({
  QueryError: ({ title }: { title: string }) => <div role="alert">{title}</div>,
}))

import { GeneralLedgerPage } from './GeneralLedgerPage'

function makeLine(overrides: Partial<LedgerLine> = {}): LedgerLine {
  return {
    id: 'll-1',
    date: '2026-06-14',
    entry_number: 'JE-0001',
    description: 'Opening balance',
    account_code: '1000',
    account_name: 'Cash',
    debit: '100.00',
    credit: '0.00',
    balance: '250.00',
    source_type: null,
    source_id: null,
    ...overrides,
  }
}

describe('GeneralLedgerPage', () => {
  beforeEach(() => {
    useLedgerMock.mockReset()
    useAccountsMock.mockReset()
    useAccountsMock.mockReturnValue({ data: [] })
  })

  it('renders exactly one h1', () => {
    useLedgerMock.mockReturnValue({
      data: { lines: [makeLine()] },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(<GeneralLedgerPage />)

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('right-aligns a money cell with tabular-nums', () => {
    useLedgerMock.mockReturnValue({
      data: { lines: [makeLine()] },
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { container } = render(<GeneralLedgerPage />)

    const moneyCell = screen.getByText('$100.00').closest('td')
    expect(moneyCell).not.toBeNull()
    expect(moneyCell?.className).toContain('tabular-nums')
    // sanity: the table itself rendered
    expect(container.querySelector('table')).not.toBeNull()
  })

  it('renders the error state through QueryError when the query fails', () => {
    useLedgerMock.mockReturnValue({
      data: undefined,
      isLoading: false,
      error: new Error('boom'),
      refetch: vi.fn(),
    })

    render(<GeneralLedgerPage />)

    expect(screen.getByRole('alert')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { level: 1 })).toBeNull()
  })
})
