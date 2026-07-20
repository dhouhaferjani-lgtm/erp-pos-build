import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import Big from 'big.js'
import { ArrowLeft, CheckCircle2, RefreshCcw, Search } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router-dom'

import { Button, Input, Select, StatusBadge } from '@/components/atoms'
import { PageHeader } from '@/components/molecules/PageHeader'
import { usePermissions } from '@/hooks/usePermissions'
import { getErrorMessage } from '@/lib/api'
import { semanticColorTokens, textColors, tokens } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useDebouncedValue } from '@/lib/hooks'
import { cn } from '@/lib/utils'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import {
  allocateStatementLine,
  completeBankStatement,
  executeStatementAction,
  getBankStatement,
  getStatementSuggestions,
  ignoreStatementLine,
  reopenBankStatement,
  searchRepositoryMovements,
  type StatementLineStatus,
  unallocateStatementLine,
  unignoreStatementLine,
} from './api'
import { LinePanel } from './LinePanel'
import { shouldRefreshWorkspaceQuery } from './queryScope'
import { StatementCompletionDialog } from './StatementCompletionDialog'
import { formatAtCurrencyScale, isResolvedLineStatus, isSuccessfulLineStatus, remainingForLine } from './status'

export function ReconciliationWorkspacePage() {
  const { t } = useTranslation(['treasury', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const [searchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { hasPermission } = usePermissions()
  const [statusFilter, setStatusFilter] = useState<StatementLineStatus | ''>('')
  const [search, setSearch] = useState('')
  const [movementSearch, setMovementSearch] = useState('')
  const debouncedMovementSearch = useDebouncedValue(movementSearch)
  const [selectedLineId, setSelectedLineId] = useState(() => searchParams.get('line') ?? '')
  const [completeOpen, setCompleteOpen] = useState(false)
  const statementQuery = useQuery({
    queryKey: tenantScopedKey(['bank-statement', id]),
    queryFn: () => getBankStatement(id),
    enabled: Boolean(id) && tenantId !== null && companyId !== null,
  })
  const statement = statementQuery.data
  const normalizedSearch = search.trim().toLocaleLowerCase()
  const filteredLines = (statement?.lines ?? []).filter((line) => {
    if (statusFilter && line.match_status !== statusFilter) return false
    if (!normalizedSearch) return true
    return [line.label, line.reference, line.value_date, String(line.line_number)]
      .some((value) => value?.toLocaleLowerCase().includes(normalizedSearch))
  })
  const selectedLine = filteredLines.find((line) => line.id === selectedLineId) ?? filteredLines[0]
  const mutable = statement?.status !== 'reconciled' && statement?.status !== 'voided' && hasPermission('bank-statements.reconcile')

  const suggestionsQuery = useQuery({
    queryKey: tenantScopedKey(['bank-statement-line-suggestions', selectedLine?.id ?? 'none']),
    queryFn: () => getStatementSuggestions(selectedLine?.id ?? ''),
    enabled: Boolean(selectedLine) && Boolean(mutable) && selectedLine?.match_status !== 'ignored',
  })
  const movementsQuery = useQuery({
    queryKey: tenantScopedKey(['repository-movements', statement?.payment_repository_id ?? 'none', { search: debouncedMovementSearch }]),
    queryFn: () => searchRepositoryMovements(statement?.payment_repository_id ?? '', debouncedMovementSearch),
    enabled: Boolean(selectedLine) && Boolean(statement) && Boolean(mutable),
  })

  async function refreshWorkspace() {
    await queryClient.invalidateQueries({
      predicate: (query) => shouldRefreshWorkspaceQuery(query.queryKey, tenantId, companyId),
    })
  }

  const allocateMutation = useMutation({ mutationFn: (input: { lineId: string; allocations: { repository_movement_id: string; amount: string }[] }) => allocateStatementLine(input.lineId, input.allocations), onSuccess: refreshWorkspace })
  const unallocateMutation = useMutation({ mutationFn: (input: { lineId: string; movementId: string }) => unallocateStatementLine(input.lineId, input.movementId), onSuccess: refreshWorkspace })
  const executeMutation = useMutation({ mutationFn: (input: { lineId: string; action: Parameters<typeof executeStatementAction>[1] }) => executeStatementAction(input.lineId, input.action), onSuccess: refreshWorkspace })
  const ignoreMutation = useMutation({ mutationFn: (input: { lineId: string; ignore: Parameters<typeof ignoreStatementLine>[1] }) => ignoreStatementLine(input.lineId, input.ignore), onSuccess: refreshWorkspace })
  const unignoreMutation = useMutation({ mutationFn: (lineId: string) => unignoreStatementLine(lineId), onSuccess: refreshWorkspace })
  const completeMutation = useMutation({ mutationFn: (acknowledged: boolean) => completeBankStatement(id, acknowledged), onSuccess: async () => { setCompleteOpen(false); await refreshWorkspace() } })
  const reopenMutation = useMutation({ mutationFn: () => reopenBankStatement(id), onSuccess: refreshWorkspace })
  const pending = allocateMutation.isPending || unallocateMutation.isPending || executeMutation.isPending || ignoreMutation.isPending || unignoreMutation.isPending
  const error = statementQuery.error ?? allocateMutation.error ?? unallocateMutation.error ?? executeMutation.error ?? ignoreMutation.error ?? unignoreMutation.error ?? completeMutation.error ?? reopenMutation.error

  if (statementQuery.isLoading) return <div className={cn('py-12 text-center', textColors.tertiary)}>{t('common:status.loading')}</div>
  if (!statement) return <div className={cn(tokens.alert.base, tokens.alert.error)}>{t('common:errors.loadingFailed')}</div>

  const resolved = statement.lines.filter((line) => isResolvedLineStatus(line.match_status)).length
  const remainingTotal = formatAtCurrencyScale(statement.lines.reduce((total, line) => total.plus(remainingForLine(line)), new Big(0)).toString(), statement.currency)
  const ignoredTotal = formatAtCurrencyScale(statement.lines.reduce((total, line) => line.match_status !== 'ignored' ? total : line.direction === 'in' ? total.plus(line.amount) : total.minus(line.amount), new Big(0)).toString(), statement.currency)
  const canComplete = resolved === statement.lines.length && statement.status !== 'reconciled' && statement.status !== 'voided' && mutable

  return (
    <div className="space-y-6">
      <PageHeader title={t('treasury:statements.workspace.title')} subtitle={`${statement.period_start} → ${statement.period_end}`} breadcrumb={<Link className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)} to="/treasury/statements"><ArrowLeft className="h-4 w-4" />{t('common:actions.back')}</Link>} actions={<><StatusBadge tone={statement.status === 'reconciled' ? 'success' : statement.status === 'voided' ? 'neutral' : 'info'}>{t(`treasury:statements.status.${statement.status}`)}</StatusBadge>{canComplete ? <Button onClick={() => { setCompleteOpen(true) }}><CheckCircle2 className="me-2 h-4 w-4" />{t('treasury:statements.workspace.complete.action')}</Button> : null}{statement.status === 'reconciled' && hasPermission('bank-statements.reopen') ? <Button variant="secondary" disabled={reopenMutation.isPending} onClick={() => { reopenMutation.mutate() }}><RefreshCcw className="me-2 h-4 w-4" />{t('treasury:statements.workspace.reopen')}</Button> : null}</>} />

      {error ? <div className={cn(tokens.alert.base, tokens.alert.error)}>{getErrorMessage(error)}</div> : null}

      <section className="grid gap-3 sm:grid-cols-3">
        <Metric label={t('treasury:statements.workspace.progress')} value={`${String(resolved)}/${String(statement.lines.length)}`} />
        <Metric label={t('treasury:statements.workspace.remaining')} value={formatCurrency(remainingTotal, { currency: statement.currency })} />
        <Metric label={t('treasury:statements.workspace.balanceDelta')} value={formatCurrency(formatAtCurrencyScale(new Big(statement.closing_balance).minus(statement.opening_balance).toString(), statement.currency), { currency: statement.currency })} />
      </section>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(24rem,0.9fr)]">
        <section className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2"><label className={tokens.label.base}>{t('treasury:statements.workspace.search')}<div className="relative"><Search className={cn('pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2', textColors.tertiary)} /><Input className="ps-9" value={search} onChange={(event) => { setSearch(event.target.value) }} /></div></label><label className={tokens.label.base}>{t('treasury:statements.workspace.filter')}<Select value={statusFilter} onChange={(event) => { const value = event.target.value; setStatusFilter(value === 'unmatched' || value === 'partial' || value === 'matched' || value === 'resolved_by_creation' || value === 'ignored' ? value : '') }}><option value="">{t('treasury:statements.workspace.allStatuses')}</option>{(['unmatched', 'partial', 'matched', 'resolved_by_creation', 'ignored'] as const).map((status) => <option key={status} value={status}>{t(`treasury:statements.workspace.status.${status}`)}</option>)}</Select></label></div>
          <div className="space-y-2">{filteredLines.map((line) => <Button variant="ghost" key={line.id} aria-pressed={line.id === selectedLine?.id} onClick={() => { setSelectedLineId(line.id); setMovementSearch('') }} className={cn('h-auto w-full justify-start rounded-lg border p-3 text-start transition-colors', line.id === selectedLine?.id ? semanticColorTokens.intent.primary.border : semanticColorTokens.border.subtle, semanticColorTokens.surface.base, tokens.table.rowHover)}><div className="flex w-full items-start justify-between gap-3"><div><p className={cn('text-xs', textColors.tertiary)}>{line.value_date} · #{line.line_number}</p><p className={cn('mt-1 font-medium', textColors.primary)}>{line.label}</p><p className={cn('mt-1 text-xs', textColors.tertiary)}>{line.reference ?? t('treasury:statements.workspace.noReference')}</p></div><div className="text-end"><p className="font-semibold tabular-nums">{line.direction === 'out' ? '−' : '+'}{formatCurrency(line.amount, { currency: statement.currency })}</p><StatusBadge tone={isSuccessfulLineStatus(line.match_status) ? 'success' : line.match_status === 'ignored' ? 'neutral' : line.match_status === 'partial' ? 'info' : 'pending'}>{t(`treasury:statements.workspace.status.${line.match_status}`)}</StatusBadge></div></div></Button>)}</div>
        </section>

        {selectedLine ? <LinePanel key={selectedLine.id} line={selectedLine} currency={statement.currency} suggestions={suggestionsQuery.data ?? []} movements={movementsQuery.data ?? []} pending={pending} onSearch={setMovementSearch} onExecute={(action) => { executeMutation.mutate({ lineId: selectedLine.id, action }) }} onCreate={(action) => { executeMutation.mutate({ lineId: selectedLine.id, action }) }} onAllocate={(allocations) => { allocateMutation.mutate({ lineId: selectedLine.id, allocations }) }} onUnallocate={(movementId) => { unallocateMutation.mutate({ lineId: selectedLine.id, movementId }) }} onIgnore={(ignore) => { ignoreMutation.mutate({ lineId: selectedLine.id, ignore }) }} onUnignore={() => { unignoreMutation.mutate(selectedLine.id) }} /> : <p className={cn('py-12 text-center', textColors.tertiary)}>{t('treasury:statements.workspace.noLines')}</p>}
      </div>

      <StatementCompletionDialog key={String(completeOpen)} isOpen={completeOpen} hasIgnoredLines={statement.lines.some((line) => line.match_status === 'ignored')} ignoredTotal={ignoredTotal} currency={statement.currency} pending={completeMutation.isPending} onClose={() => { setCompleteOpen(false) }} onConfirm={(acknowledged) => { completeMutation.mutate(acknowledged) }} />
    </div>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return <div className={cn('rounded-lg border p-4', semanticColorTokens.border.subtle, semanticColorTokens.surface.pageAlpha)}><p className={cn('text-xs', textColors.tertiary)}>{label}</p><p className="mt-1 text-xl font-semibold tabular-nums">{value}</p></div>
}
