import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { formatCurrency, formatDate } from '@/lib/format'

import { LinePanel } from './LinePanel'
import type { BankStatementLine, RepositoryMovementCandidate, StatementSuggestion } from './api'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) =>
      options && 'count' in options ? `${key}#${String(options['count'])}` : key,
  }),
}))

vi.mock('@/features/finance/hooks/useAccounts', () => ({
  useAccounts: () => ({ data: [], isLoading: false }),
}))

const line: BankStatementLine = {
  id: 'line-1',
  line_number: 1,
  value_date: '2026-07-18',
  booking_date: null,
  direction: 'out',
  amount: '100.000',
  reference: 'CHQ-42',
  label: 'Supplier cheque',
  match_status: 'partial',
  ignore_reason: null,
  ignore_text: null,
  location_id: null,
  allocations: [{ repository_movement_id: 'movement-existing', matched_amount: '25.000', match_type: 'manual', movement_direction: 'out' }],
  executions: [],
}

const actionSuggestion: StatementSuggestion = {
  tier: 3,
  kind: 'action',
  movement_ids: [],
  action_type: 'outbound_clear',
  target_type: 'payment_instrument',
  target_id: 'instrument-1',
  amount: '75.000',
  reason: 'Cheque amount and date match.',
  reason_code: 'pending_instrument',
  reason_params: {},
  reference_matched: true,
  action_params: { instrument_id: 'instrument-1' },
}

const movement: RepositoryMovementCandidate = {
  id: 'movement-1',
  direction: 'out',
  amount: '80.000',
  allocated_amount: '20.000',
  remaining_allocatable_amount: '60.000',
  currency: 'TND',
  balance_after: '500.000',
  ordinal: 12,
  source_type: 'payment',
  source_id: 'payment-1',
  journal_entry_id: null,
  reason_code: null,
  occurred_at: '2026-07-18T10:00:00Z',
}

describe('LinePanel', () => {
  it('confirms an action suggestion with the execute-and-allocate request shape', () => {
    const onExecute = vi.fn()
    render(<LinePanel line={line} currency="TND" suggestions={[actionSuggestion]} movements={[]} onExecute={onExecute} onAllocate={vi.fn()} onIgnore={vi.fn()} onUnignore={vi.fn()} onCreate={vi.fn()} />)

    fireEvent.click(screen.getByRole('button', { name: 'statements.workspace.suggestions.confirm' }))

    expect(onExecute).toHaveBeenCalledWith({
      action: 'outbound_clear',
      params: { instrument_id: 'instrument-1' },
    })
  })

  it('shows precision-safe remaining amounts and submits a partial manual allocation', () => {
    const onAllocate = vi.fn()
    render(<LinePanel line={line} currency="TND" suggestions={[]} movements={[movement]} onExecute={vi.fn()} onAllocate={onAllocate} onIgnore={vi.fn()} onUnignore={vi.fn()} onCreate={vi.fn()} />)

    expect(screen.getByText(formatCurrency('75.000', { currency: 'TND' }))).toBeInTheDocument()
    expect(screen.getByText(formatCurrency('60.000', { currency: 'TND' }))).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('statements.workspace.manual.amount'), { target: { value: '50.000' } })
    fireEvent.click(screen.getByRole('button', { name: 'statements.workspace.manual.allocate' }))

    expect(onAllocate).toHaveBeenCalledWith([{ repository_movement_id: 'movement-1', amount: '50.000' }])
    expect(screen.getByText(/statements\.workspace\.matchType\.manual/)).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /statements\.workspace\.sourceType\.payment/ })).not.toHaveTextContent('payment-1')
  })

  it('uses the selected currency scale for remaining capacity and allocations', () => {
    render(<LinePanel line={{ ...line, amount: '100.00', allocations: [] }} currency="EUR" suggestions={[]} movements={[{ ...movement, currency: 'EUR', amount: '80.00', allocated_amount: '20.00', remaining_allocatable_amount: '60.00' }]} onExecute={vi.fn()} onAllocate={vi.fn()} onIgnore={vi.fn()} onUnignore={vi.fn()} onCreate={vi.fn()} />)

    expect(screen.getByLabelText('statements.workspace.manual.amount')).toHaveAttribute('step', '0.01')
    expect(screen.getByLabelText('statements.workspace.manual.amount')).toHaveAttribute('max', '60.00')
  })

  it('requires an ignore reason and explanation before submitting', () => {
    const onIgnore = vi.fn()
    render(<LinePanel line={{ ...line, allocations: [], match_status: 'unmatched' }} currency="TND" suggestions={[]} movements={[]} onExecute={vi.fn()} onAllocate={vi.fn()} onIgnore={onIgnore} onUnignore={vi.fn()} onCreate={vi.fn()} />)

    const ignore = screen.getByRole('group', { name: 'statements.workspace.ignore.title' })
    const submit = within(ignore).getByRole('button', { name: 'statements.workspace.ignore.submit' })
    expect(submit).toBeDisabled()
    fireEvent.change(within(ignore).getByLabelText('statements.workspace.ignore.reason'), { target: { value: 'informational' } })
    fireEvent.change(within(ignore).getByLabelText('statements.workspace.ignore.explanation'), { target: { value: 'Bank advice only' } })
    fireEvent.click(submit)

    expect(onIgnore).toHaveBeenCalledWith({ reason: 'informational', text: 'Bank advice only' })
  })

  it('renders localized dates instead of raw ISO strings', () => {
    render(<LinePanel line={line} currency="TND" suggestions={[]} movements={[movement]} onExecute={vi.fn()} onAllocate={vi.fn()} onIgnore={vi.fn()} onUnignore={vi.fn()} onCreate={vi.fn()} />)

    expect(screen.getAllByText(new RegExp(formatDate('2026-07-18'))).length).toBeGreaterThan(0)
    expect(screen.getByRole('option', { name: /statements\.workspace\.sourceType\.payment/ })).toHaveTextContent(formatDate('2026-07-18T10:00:00Z'))
    expect(screen.queryByText(/2026-07-18/)).not.toBeInTheDocument()
  })

  it('pluralizes produced movements through i18n count instead of string concatenation', () => {
    const executedLine: BankStatementLine = {
      ...line,
      match_status: 'matched',
      allocations: [],
      executions: [
        {
          action_type: 'outbound_clear',
          target_type: null,
          target_id: null,
          produced_repository_movement_ids: ['movement-a', 'movement-b'],
        },
      ],
    }
    render(<LinePanel line={executedLine} currency="TND" suggestions={[]} movements={[]} onExecute={vi.fn()} onAllocate={vi.fn()} onIgnore={vi.fn()} onUnignore={vi.fn()} onCreate={vi.fn()} />)

    expect(screen.getByText('statements.workspace.movementsProduced#2')).toBeInTheDocument()
    expect(screen.queryByText(/^2 statements\.workspace\.movementsProduced$/)).not.toBeInTheDocument()
  })

  it('does not render mutation controls for a reconciled or view-only statement', () => {
    render(<LinePanel mutable={false} line={line} currency="TND" suggestions={[]} movements={[movement]} onExecute={vi.fn()} onAllocate={vi.fn()} onIgnore={vi.fn()} onUnignore={vi.fn()} onCreate={vi.fn()} onUnallocate={vi.fn()} />)

    expect(screen.queryByRole('button', { name: 'statements.workspace.unallocate' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'statements.workspace.suggestions.title' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'statements.workspace.manual.title' })).not.toBeInTheDocument()
    expect(screen.queryByRole('group', { name: 'statements.workspace.ignore.title' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /statements\.workspace\.create\./ })).not.toBeInTheDocument()
  })

  it('does not render the unignore action for an ignored read-only line', () => {
    render(<LinePanel mutable={false} line={{ ...line, match_status: 'ignored', allocations: [], ignore_reason: 'informational', ignore_text: 'Reviewed' }} currency="TND" suggestions={[]} movements={[]} onExecute={vi.fn()} onAllocate={vi.fn()} onIgnore={vi.fn()} onUnignore={vi.fn()} onCreate={vi.fn()} />)

    expect(screen.queryByRole('button', { name: 'statements.workspace.ignore.unignore' })).not.toBeInTheDocument()
  })
})
