import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { LedgerHistoryTable } from '../LedgerHistoryTable'
import type { VoucherLedgerRow } from '../../types/voucher'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

const makeRow = (overrides: Partial<VoucherLedgerRow>): VoucherLedgerRow => ({
  id: 'l1',
  event: 'Issued',
  amount: '100.00',
  running_balance: '100.00',
  receipt_id: null,
  receipt_number: null,
  terminal_id: null,
  terminal_name: null,
  user_id: null,
  user_name: null,
  policy_trigger: null,
  notes: null,
  occurred_at: '2026-04-01T10:00:00Z',
  ...overrides,
})

describe('LedgerHistoryTable', () => {
  it('renders Issued row with positive amount and plus prefix', () => {
    const rows = [makeRow({ id: 'l1', event: 'Issued', amount: '100.00' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    expect(screen.getByText((content) => content.includes('+') && content.includes('100.00'))).toBeInTheDocument()
  })

  it('renders Redeemed row with minus prefix and negative color class', () => {
    const rows = [makeRow({ id: 'l2', event: 'Redeemed', amount: '30.00' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    const amountEl = screen.getByText((content) => content.includes('−') && content.includes('30.00'))
    expect(amountEl).toBeInTheDocument()
    // The span should have the red color class
    expect(amountEl.className).toContain('text-red-700')
  })

  it('renders PartiallyRedeemed as redeemed-class badge with minus amount', () => {
    // PartiallyRedeemed is a status, not a ledger event — test Redeemed event badge
    const rows = [makeRow({ id: 'l3', event: 'Redeemed', amount: '20.00' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    const badge = screen.getByTestId('event-badge-redeemed')
    expect(badge).toBeInTheDocument()
    expect(badge.textContent).toBe('vouchers:events.Redeemed')
  })

  it('renders Voided row with voided event badge', () => {
    const rows = [makeRow({ id: 'l4', event: 'Voided', amount: '100.00' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    const badge = screen.getByTestId('event-badge-voided')
    expect(badge).toBeInTheDocument()
    expect(badge.textContent).toBe('vouchers:events.Voided')
    // Voided is a negative-ish event (not in positive list) — amount gets minus prefix
    expect(screen.getByText((content) => content.includes('−') && content.includes('100.00'))).toBeInTheDocument()
  })

  it('renders receipt link when receipt_id is present', () => {
    const rows = [makeRow({ id: 'l5', event: 'Redeemed', receipt_id: 'r1', receipt_number: 'REC-001' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    expect(screen.getByRole('link', { name: 'REC-001' })).toBeInTheDocument()
  })
})
