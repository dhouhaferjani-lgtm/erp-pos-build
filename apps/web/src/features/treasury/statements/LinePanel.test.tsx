import { fireEvent, render, screen, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { formatCurrency } from '@/lib/format'

import { LinePanel } from './LinePanel'
import type { BankStatementLine, RepositoryMovementCandidate, StatementSuggestion } from './api'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
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
})
