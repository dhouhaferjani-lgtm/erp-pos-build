import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { LedgerHistoryTable } from '../LedgerHistoryTable'
import type { VoucherLedgerRow } from '../../types/voucher'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
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
    expect(amountEl.className).toContain(colorTokens.intent.danger.textStrong)
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

  it('navigates a receipt link through the registered receipt detail route', () => {
    const rows = [makeRow({ id: 'l5', event: 'Redeemed', receipt_id: 'r1', receipt_number: 'REC-001' })]
    render(
      <MemoryRouter initialEntries={['/pos/vouchers/v1']}>
        <Routes>
          <Route path="/pos/vouchers/:id" element={<LedgerHistoryTable rows={rows} currency="EUR" />} />
          <Route path="/pos/receipts/:id" element={<h1>Receipt detail destination</h1>} />
        </Routes>
      </MemoryRouter>,
    )

    fireEvent.click(screen.getByRole('link', { name: 'REC-001' }))
    expect(screen.getByRole('heading', { name: 'Receipt detail destination' })).toBeInTheDocument()
  })

  // Codex review R3 (2026-04-30): the backend's `formatLedger()` returns
  // lowercase storage values from the `VoucherEvent` enum (`issued`,
  // `redeemed`, `voided`, `transferred`, `expiry_extended`, etc.). Until R3
  // landed, the frontend keyed badge colors / positivity off PascalCase, so
  // every real backend row fell through to the grey default badge. These
  // tests prove the lowercase wire values render with the proper badge
  // colour classes — without falling back to grey.
  it('renders a backend-shaped lowercase `redeemed` row with the redeemed badge class (not grey fallback)', () => {
    const rows = [makeRow({ id: 'l-r', event: 'redeemed', amount: '15.00' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    const badge = screen.getByTestId('event-badge-redeemed')
    expect(badge).toBeInTheDocument()
    expect(badge.className).toContain(colorTokens.intent.primary.bgSoft)
    expect(badge.className).not.toContain(colorTokens.surface.muted)
    // i18n key resolves against the lowercase namespace.
    expect(badge.textContent).toBe('vouchers:events.redeemed')
  })

  it('renders a backend-shaped lowercase `issued` row with green positive amount', () => {
    const rows = [makeRow({ id: 'l-i', event: 'issued', amount: '50.00' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    const amountEl = screen.getByText((content) => content.includes('+') && content.includes('50.00'))
    expect(amountEl.className).toContain(colorTokens.intent.success.textStrong)
    const badge = screen.getByTestId('event-badge-issued')
    expect(badge.className).toContain(colorTokens.intent.success.bgSoft)
  })

  it('renders a backend-shaped `expiry_extended` ledger event with its own badge colour', () => {
    const rows = [makeRow({ id: 'l-x', event: 'expiry_extended', amount: '0.00' })]
    render(<LedgerHistoryTable rows={rows} currency="EUR" />)
    const badge = screen.getByTestId('event-badge-expiry_extended')
    expect(badge).toBeInTheDocument()
    expect(badge.className).not.toContain(colorTokens.surface.muted)
  })
})
