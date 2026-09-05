import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { formatCurrency } from '../../../lib/format'
import type { LedgerLine } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// The tenant currency drives BOTH the symbol/code and the scale. Held in a
// hoisted ref so a single test can switch tenants (TND scale 3 -> EUR scale 2)
// and prove the scale is currency-derived rather than hardcoded.
const { companyRef } = vi.hoisted(() => ({
  companyRef: {
    current: { currency: 'TND', locale: 'fr_TN' } as {
      currency: string
      locale: string
    },
  },
}))

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: companyRef.current,
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

/**
 * Wire-format fixture.
 *
 * The API emits SCALE-4 decimal strings, not scale-2: see
 * `apps/api/app/Modules/Accounting/Application/Services/Reports/GeneralLedgerReportService.php`
 * (`DECIMAL_SCALE = 4`; `'debit' => CurrencyScale::bcformat($line->debit, 4)`,
 * `'credit' => ...`, `balance` = a `bcadd/bcsub(..., 4)` result) and the example
 * payload in `apps/api/app/Modules/Accounting/Application/DTOs/Reports/LedgerData.php`
 * (`"debit": "500.0000"`, `"credit": "0.0000"`). Fixtures at scale 2 cannot
 * catch a presentation guard that string-compares against `'0.00'`.
 */
function makeLine(overrides: Partial<LedgerLine> = {}): LedgerLine {
  return {
    id: 'll-1',
    date: '2026-06-14',
    entry_number: 'JE-0001',
    description: 'Opening balance',
    account_code: '1000',
    account_name: 'Cash',
    debit: '100.0000',
    credit: '0.0000',
    balance: '250.0000',
    source_type: null,
    source_id: null,
    ...overrides,
  }
}

function mockLedger(lines: LedgerLine[]): void {
  useLedgerMock.mockReturnValue({
    data: { lines },
    isLoading: false,
    error: null,
    refetch: vi.fn(),
  })
}

/** Body cells of the first data row. Column order: date, entry, account, description, debit, credit, balance. */
function firstRowCells(container: HTMLElement): HTMLTableCellElement[] {
  const cells = Array.from(
    container.querySelectorAll<HTMLTableCellElement>('tbody tr:first-of-type td')
  )
  expect(cells.length).toBeGreaterThan(0)
  return cells
}

/** Text of the Nth-from-last body cell (balance = 1, credit = 2, debit = 3). */
function cellFromEnd(cells: HTMLTableCellElement[], fromEnd: number): string {
  return cells[cells.length - fromEnd]?.textContent ?? '<missing cell>'
}

describe('GeneralLedgerPage', () => {
  beforeEach(() => {
    useLedgerMock.mockReset()
    useAccountsMock.mockReset()
    useAccountsMock.mockReturnValue({ data: [] })
    companyRef.current = { currency: 'TND', locale: 'fr_TN' }
  })

  it('renders exactly one h1', () => {
    mockLedger([makeLine()])

    render(<GeneralLedgerPage />)

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('right-aligns a money cell with tabular-nums', () => {
    mockLedger([makeLine()])

    const { container } = render(<GeneralLedgerPage />)

    // The debit (100.0000) renders through the shared tenant-currency formatter,
    // NOT a hardcoded "$". For a TND/fr_TN tenant that is "100,000 TND".
    const moneyCell = screen.getByText('100,000 TND').closest('td')
    expect(moneyCell).not.toBeNull()
    expect(moneyCell?.className).toContain('tabular-nums')
    // no dollar sign anywhere in the rendered ledger
    expect(container.textContent).not.toContain('$')
    // sanity: the table itself rendered
    expect(container.querySelector('table')).not.toBeNull()
    // and the expected literal agrees with the shared formatter
    expect(formatCurrency('100.0000', { currency: 'TND', locale: 'fr-TN' })).toBe(
      '100,000 TND'
    )
  })

  it('blanks a zero credit and formats a non-zero debit on the SAME scale-4 row', () => {
    mockLedger([makeLine({ debit: '100.0000', credit: '0.0000' })])

    const { container } = render(<GeneralLedgerPage />)

    const cells = firstRowCells(container)

    // RED without the decimal-zero guard: a string compare against '0.00'
    // never matches the wire value '0.0000', so this cell would read '0,000 TND'.
    expect(cellFromEnd(cells, 2)).toBe('')
    // the non-zero side on the same row is still formatted
    expect(cellFromEnd(cells, 3)).toBe('100,000 TND')
    // ...and the zero never reaches the DOM as a formatted amount
    expect(screen.queryByText('0,000 TND')).toBeNull()
  })

  it('renders a non-zero credit (the blank rule only fires on zero)', () => {
    mockLedger([makeLine({ debit: '0.0000', credit: '100.0000' })])

    const { container } = render(<GeneralLedgerPage />)

    const cells = firstRowCells(container)
    expect(cellFromEnd(cells, 2)).toBe('100,000 TND')
    expect(cellFromEnd(cells, 3)).toBe('')
  })

  it('derives the scale from the tenant currency (EUR tenant renders 2 decimals)', () => {
    companyRef.current = { currency: 'EUR', locale: 'fr_FR' }
    mockLedger([makeLine({ debit: '100.0000', credit: '0.0000' })])

    const { container } = render(<GeneralLedgerPage />)

    const cells = firstRowCells(container)
    // RED if the scale were hardcoded to TND's 3 ('100,000 EUR') or to the
    // raw scale-4 string ('100,0000 EUR').
    expect(cellFromEnd(cells, 3)).toBe('100,00 EUR')
    expect(container.textContent).not.toContain('100,000 EUR')
    expect(container.textContent).not.toContain('$')
    expect(cellFromEnd(cells, 2)).toBe('')
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
