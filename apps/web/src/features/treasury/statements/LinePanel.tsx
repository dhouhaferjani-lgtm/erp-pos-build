import { useState } from 'react'
import Big from 'big.js'
import { FilePlus2, RotateCcw, Unlink } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { Button, Select, StatusBadge, Textarea } from '@/components/atoms'
import { semanticColorTokens, textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import type { BankStatementLine, RepositoryMovementCandidate, StatementActionType, StatementIgnoreReason, StatementSuggestion } from './api'
import { CreateFromLineDialog } from './CreateFromLineDialog'
import { ManualMatchSearch } from './ManualMatchSearch'
import { SuggestionList } from './SuggestionList'
import { isSuccessfulLineStatus, remainingForLine } from './status'

interface LinePanelProps {
  line: BankStatementLine
  currency: string
  suggestions: StatementSuggestion[]
  movements: RepositoryMovementCandidate[]
  pending?: boolean
  onSearch?: (value: string) => void
  onExecute: (input: { action: StatementActionType; params: Record<string, unknown> }) => void
  onAllocate: (allocations: { repository_movement_id: string; amount: string }[]) => void
  onUnallocate?: (movementId: string) => void
  onIgnore: (input: { reason: StatementIgnoreReason; text: string }) => void
  onUnignore: () => void
  onCreate: (input: { action: StatementActionType; params: Record<string, unknown> }) => void
}

const ignoreReasons: StatementIgnoreReason[] = ['duplicate', 'informational', 'bank_error', 'out_of_scope', 'other']

function isIgnoreReason(value: string): value is StatementIgnoreReason {
  return ignoreReasons.some((reason) => reason === value)
}

function provenanceLink(targetType: string | null, targetId: string | null): string | null {
  if (!targetId) return null
  if (targetType === 'payment_instrument') return `/treasury/instruments/${targetId}`
  if (targetType === 'expense_document') return `/expenses/${targetId}/view`
  if (targetType === 'income_document') return `/income/${targetId}/edit`
  return null
}

export function LinePanel({ line, currency, suggestions, movements, pending = false, onSearch, onExecute, onAllocate, onUnallocate, onIgnore, onUnignore, onCreate }: LinePanelProps) {
  const { t } = useTranslation('treasury')
  const [manualSearch, setManualSearch] = useState('')
  const [manualAmount, setManualAmount] = useState('')
  const [ignoreReason, setIgnoreReason] = useState<StatementIgnoreReason | ''>('')
  const [ignoreText, setIgnoreText] = useState('')
  const [showCreate, setShowCreate] = useState(false)
  const remaining = remainingForLine(line).toFixed(3)
  const canIgnore = line.allocations.length === 0 && line.executions.length === 0

  function confirmSuggestion(suggestion: StatementSuggestion) {
    if (suggestion.action_type) {
      onExecute({ action: suggestion.action_type, params: suggestion.action_params })
      return
    }
    const movementId = suggestion.movement_ids[0]
    if (movementId) onAllocate([{ repository_movement_id: movementId, amount: suggestion.amount }])
  }

  return (
    <aside className="space-y-6">
      <header className={cn('rounded-lg border p-4', semanticColorTokens.border.subtle, semanticColorTokens.surface.pageAlpha)}>
        <div className="flex items-start justify-between gap-3"><div><p className={cn('text-xs', textColors.tertiary)}>{line.value_date} · #{line.line_number}</p><h2 className={cn('mt-1 font-semibold', textColors.primary)}>{line.label}</h2><p className={cn('mt-1 text-sm', textColors.secondary)}>{line.reference ?? t('statements.workspace.noReference')}</p></div><StatusBadge tone={isSuccessfulLineStatus(line.match_status) ? 'success' : line.match_status === 'ignored' ? 'neutral' : line.match_status === 'partial' ? 'info' : 'pending'}>{t(`statements.workspace.status.${line.match_status}`)}</StatusBadge></div>
        <div className="mt-4 grid grid-cols-2 gap-3"><div><p className={cn('text-xs', textColors.tertiary)}>{t('statements.workspace.lineAmount')}</p><p className="font-semibold tabular-nums">{formatCurrency(line.amount, { currency })}</p></div><div><p className={cn('text-xs', textColors.tertiary)}>{t('statements.workspace.remaining')}</p><p className="font-semibold tabular-nums">{formatCurrency(remaining, { currency })}</p></div></div>
      </header>

      {line.match_status === 'ignored' ? <div className={cn(tokens.alert.base, tokens.alert.warning)}><div><strong>{t(`statements.workspace.ignore.reasons.${line.ignore_reason ?? 'other'}`)}</strong><p>{line.ignore_text}</p></div><Button size="sm" variant="secondary" disabled={pending} onClick={onUnignore}><RotateCcw className="me-2 h-4 w-4" />{t('statements.workspace.ignore.unignore')}</Button></div> : <>
        <SuggestionList suggestions={suggestions} currency={currency} disabled={pending} onConfirm={confirmSuggestion} />
        <ManualMatchSearch movements={movements.filter((movement) => movement.direction === line.direction && new Big(movement.remaining_allocatable_amount).gt(0))} currency={currency} lineRemaining={remaining} search={manualSearch} amount={manualAmount} disabled={pending || new Big(remaining).eq(0)} onSearch={(value) => { setManualSearch(value); onSearch?.(value) }} onAmountChange={setManualAmount} onAllocate={(movementId, amount) => { onAllocate([{ repository_movement_id: movementId, amount }]); setManualAmount('') }} />

        {line.allocations.length ? <section className="space-y-2"><h3 className={cn('text-sm font-semibold', textColors.primary)}>{t('statements.workspace.allocations')}</h3>{line.allocations.map((allocation) => <div key={allocation.repository_movement_id} className={cn('flex items-center justify-between rounded-lg border p-3 text-sm', semanticColorTokens.border.subtle)}><span>{allocation.match_type} · {formatCurrency(allocation.matched_amount, { currency })}</span>{onUnallocate ? <Button variant="ghost" size="sm" disabled={pending} onClick={() => { onUnallocate(allocation.repository_movement_id) }}><Unlink className="me-2 h-4 w-4" />{t('statements.workspace.unallocate')}</Button> : null}</div>)}</section> : null}

        {canIgnore ? <fieldset aria-label={t('statements.workspace.ignore.title')} className={cn('space-y-3 rounded-lg border p-4', semanticColorTokens.border.subtle)}><legend className={cn('px-1 text-sm font-semibold', textColors.primary)}>{t('statements.workspace.ignore.title')}</legend><label className={tokens.label.base}>{t('statements.workspace.ignore.reason')}<Select aria-label={t('statements.workspace.ignore.reason')} value={ignoreReason} onChange={(event) => { const value = event.target.value; setIgnoreReason(isIgnoreReason(value) ? value : '') }}><option value="">{t('statements.workspace.ignore.selectReason')}</option>{ignoreReasons.map((reason) => <option key={reason} value={reason}>{t(`statements.workspace.ignore.reasons.${reason}`)}</option>)}</Select></label><label className={tokens.label.base}>{t('statements.workspace.ignore.explanation')}<Textarea aria-label={t('statements.workspace.ignore.explanation')} value={ignoreText} onChange={(event) => { setIgnoreText(event.target.value) }} /></label><Button variant="secondary" disabled={pending || !ignoreReason || !ignoreText.trim()} onClick={() => { if (ignoreReason) onIgnore({ reason: ignoreReason, text: ignoreText }) }}>{t('statements.workspace.ignore.submit')}</Button></fieldset> : null}

        {canIgnore ? <Button variant="secondary" disabled={pending || !new Big(remaining).eq(line.amount)} onClick={() => { setShowCreate(true) }}><FilePlus2 className="me-2 h-4 w-4" />{t(line.direction === 'out' ? 'statements.workspace.create.expense' : 'statements.workspace.create.income')}</Button> : null}
      </>}

      {line.executions.length ? <section className="space-y-2"><h3 className={cn('text-sm font-semibold', textColors.primary)}>{t('statements.workspace.provenance')}</h3>{line.executions.map((execution) => { const link = provenanceLink(execution.target_type, execution.target_id); return <div key={`${execution.action_type}-${execution.target_id ?? ''}`} className={cn('rounded-lg border p-3 text-sm', semanticColorTokens.border.subtle)}><StatusBadge tone="success">{t(`statements.workspace.actions.${execution.action_type}`)}</StatusBadge>{link ? <Link className={cn('ms-2 font-medium hover:underline', textColors.brand)} to={link}>{t('statements.workspace.openTarget')}</Link> : null}<p className={cn('mt-1 text-xs', textColors.tertiary)}>{execution.produced_repository_movement_ids.length} {t('statements.workspace.movementsProduced')}</p></div> })}</section> : null}

      <CreateFromLineDialog isOpen={showCreate} direction={line.direction} pending={pending} onClose={() => { setShowCreate(false) }} onSubmit={(input) => { onCreate(input); setShowCreate(false) }} />
    </aside>
  )
}
