import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft,
  Plus,
  Check,
  X,
  Building2,
  Calendar,
  AlertCircle,
  CheckCircle2,
  Eye,
} from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors } from '../../lib/designTokens'
import {
  Button,
  FormField,
  Input,
  MoneyInput,
  Select,
  StatusBadge,
  statusTone,
} from '../../components/atoms'
import {
  DataTable,
  type DataTableColumn,
  FilterTabs,
  PageHeader,
} from '../../components/molecules'
import { Modal, ModalContent, ModalFooter } from '../../components/organisms/Modal'
import {
  useReconciliations,
  useReconciliation,
  useReconciliationSummary,
  usePaymentRepositories,
  useStartReconciliation,
  useMatchItem,
  useUnmatchItem,
  useCompleteReconciliation,
  useCancelReconciliation,
} from './hooks/useReconciliation'
import type {
  BankReconciliation,
  BankReconciliationItem,
} from '@/types/treasury'
import { bccomp, bcsub, formatCurrency as formatDecimalCurrency } from '@/lib/decimal'

// Format currency amount
function formatCurrency(amount: string | number, currency = 'USD'): string {
  return formatDecimalCurrency(amount, true, currency)
}

// Format date
function formatDate(dateString: string | null): string {
  if (!dateString) return '-'
  return new Date(dateString).toLocaleDateString('fr-TN', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

// Start reconciliation modal
function StartReconciliationModal({
  isOpen,
  onClose,
  onSubmit,
  isLoading,
}: {
  isOpen: boolean
  onClose: () => void
  onSubmit: (data: { repository_id: string; statement_date: string; opening_balance?: string; statement_balance: string; notes?: string }) => void
  isLoading: boolean
}) {
  const { t } = useTranslation()
  const { data: repositories, isLoading: loadingRepositories } = usePaymentRepositories()

  const [repositoryId, setRepositoryId] = useState('')
  const [statementDate, setStatementDate] = useState(new Date().toISOString().split('T')[0])
  const [openingBalance, setOpeningBalance] = useState('')
  const [statementBalance, setStatementBalance] = useState('')
  const [notes, setNotes] = useState('')

  const selectedRepository = repositories?.find((repo) => repo.id === repositoryId)
  const selectedCurrency = selectedRepository?.currency ?? 'USD'
  const defaultOpeningBalance = selectedRepository?.last_reconciled_balance ?? '0.000'
  const effectiveOpeningBalance = openingBalance.trim() === '' ? defaultOpeningBalance : openingBalance
  const liveDifference = bcsub(statementBalance || '0', effectiveOpeningBalance, 3)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!repositoryId || !statementDate || !statementBalance) return
    const data: { repository_id: string; statement_date: string; opening_balance?: string; statement_balance: string; notes?: string } = {
      repository_id: repositoryId,
      statement_date: statementDate,
      statement_balance: statementBalance,
    }
    if (openingBalance.trim() !== '') {
      data.opening_balance = openingBalance
    }
    if (notes) {
      data.notes = notes
    }
    onSubmit(data)
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={t('reconciliation.startNew')}>
      <form onSubmit={handleSubmit}>
        <ModalContent>
          <FormField label={t('reconciliation.repository')} htmlFor="reconciliation-repository" required>
            <Select
              id="reconciliation-repository"
              value={repositoryId}
              onChange={(e) => { setRepositoryId(e.target.value); }}
              required
              disabled={loadingRepositories}
            >
              <option value="">{t('reconciliation.selectRepository')}</option>
              {repositories?.map((repo) => (
                <option key={repo.id} value={repo.id}>
                  {repo.name} ({formatCurrency(repo.balance, repo.currency)})
                </option>
              ))}
            </Select>
          </FormField>

          <FormField label={t('reconciliation.statementDate')} htmlFor="reconciliation-statement-date" required>
            <Input
              id="reconciliation-statement-date"
              type="date"
              value={statementDate}
              onChange={(e) => { setStatementDate(e.target.value); }}
              required
            />
          </FormField>

          <FormField label={t('reconciliation.statementBalance')} htmlFor="reconciliation-statement-balance" required>
            <MoneyInput
              id="reconciliation-statement-balance"
              value={statementBalance}
              onChange={setStatementBalance}
              currency={selectedCurrency}
              min="-999999999999.999"
              placeholder="0.00"
              required
            />
          </FormField>

          <FormField label={t('treasury:reconciliation.openingBalance')} htmlFor="reconciliation-opening-balance">
            <MoneyInput
              id="reconciliation-opening-balance"
              value={openingBalance}
              onChange={setOpeningBalance}
              currency={selectedCurrency}
              min="-999999999999.999"
              placeholder={defaultOpeningBalance}
            />
            <p className={cn('mt-1 text-xs', textColors.tertiary)}>
              {t('treasury:reconciliation.startModal.openingBalanceHelp', {
                amount: formatCurrency(defaultOpeningBalance, selectedCurrency),
              })}
            </p>
          </FormField>

          <div className={cn('rounded-md border px-3 py-2 text-sm', borderColors.light, tokens.table.header)}>
            <span className={textColors.tertiary}>
              {t('treasury:reconciliation.startModal.liveDifference')}
            </span>
            <span className={cn('ms-2 font-semibold tabular-nums', bccomp(liveDifference, '0') === 0 ? textColors.success : textColors.warningDark)}>
              {formatCurrency(liveDifference, selectedCurrency)}
            </span>
          </div>

          <FormField label={t('fields.notes')} htmlFor="reconciliation-notes">
            <Input
              id="reconciliation-notes"
              value={notes}
              onChange={(e) => { setNotes(e.target.value); }}
            />
          </FormField>
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('cancel')}
          </Button>
          <Button type="submit" disabled={isLoading || !repositoryId}>
            {isLoading ? t('loading') : t('reconciliation.startNew')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}

// Single reconciliation item row — two-pane matching UI kept inline, tokenized.
function ReconciliationItemRow({
  item,
  isEditable,
  bankReference,
  matchingPaymentId,
  onChangeBankReference,
  onStartMatch,
  onConfirmMatch,
  onCancelMatch,
  onUnmatch,
  matchPending,
  unmatchPending,
}: {
  item: BankReconciliationItem
  isEditable: boolean
  bankReference: string
  matchingPaymentId: string | null
  onChangeBankReference: (value: string) => void
  onStartMatch: (paymentId: string) => void
  onConfirmMatch: (paymentId: string) => void
  onCancelMatch: () => void
  onUnmatch: (paymentId: string) => void
  matchPending: boolean
  unmatchPending: boolean
}) {
  const { t } = useTranslation()

  return (
    <div
      className={cn(
        'flex items-center gap-4 p-4',
        item.is_matched && tokens.alert.success,
      )}
    >
      <div className="flex-1">
        <div className="flex items-center gap-2">
          <span className={cn('font-medium', textColors.primary)}>{item.payment_reference}</span>
          {item.is_matched && (
            <CheckCircle2 className={cn('h-4 w-4', textColors.success)} />
          )}
        </div>
        <div className={cn('mt-1 flex gap-4 text-sm', textColors.tertiary)}>
          <span>{formatDate(item.payment_date)}</span>
          {item.partner_name && <span>{item.partner_name}</span>}
          {item.bank_reference && (
            <span className={textColors.brand}>{item.bank_reference}</span>
          )}
        </div>
      </div>

      <div className="text-end">
        <p className={cn('font-semibold tabular-nums', textColors.primary)}>
          {formatCurrency(item.payment_amount)}
        </p>
      </div>

      {isEditable && (
        <div className="flex items-center gap-2">
          {item.is_matched ? (
            <Button
              variant="secondary"
              onClick={() => { onUnmatch(item.payment_id); }}
              disabled={unmatchPending}
            >
              <X className="h-4 w-4" />
              {t('reconciliation.items.unmatch')}
            </Button>
          ) : matchingPaymentId === item.payment_id ? (
            <div className="flex items-center gap-2">
              <Input
                value={bankReference}
                onChange={(e) => { onChangeBankReference(e.target.value); }}
                placeholder={t('reconciliation.items.bankReferencePlaceholder')}
                className="mt-0 w-40 py-1 text-sm"
              />
              <Button
                onClick={() => { onConfirmMatch(item.payment_id); }}
                disabled={matchPending}
              >
                <Check className="h-4 w-4" />
              </Button>
              <Button variant="secondary" onClick={onCancelMatch}>
                <X className="h-4 w-4" />
              </Button>
            </div>
          ) : (
            <Button
              variant="secondary"
              onClick={() => { onStartMatch(item.payment_id); }}
            >
              <Check className="h-4 w-4" />
              {t('reconciliation.items.match')}
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

// Reconciliation detail view
function ReconciliationDetail({
  reconciliationId,
  onClose,
}: {
  reconciliationId: string
  onClose: () => void
}) {
  const { t } = useTranslation()
  const { data: reconciliation, isLoading, error } = useReconciliation(reconciliationId)
  const { data: summary } = useReconciliationSummary(reconciliationId)

  const matchMutation = useMatchItem()
  const unmatchMutation = useUnmatchItem()
  const completeMutation = useCompleteReconciliation()
  const cancelMutation = useCancelReconciliation()

  const [bankReference, setBankReference] = useState('')
  const [matchingPaymentId, setMatchingPaymentId] = useState<string | null>(null)
  const [showCompleteConfirm, setShowCompleteConfirm] = useState(false)
  const [showCancelConfirm, setShowCancelConfirm] = useState(false)

  const handleMatch = (paymentId: string) => {
    const mutateData: { reconciliationId: string; paymentId: string; request?: { bank_reference: string } } = {
      reconciliationId,
      paymentId,
    }
    if (bankReference) {
      mutateData.request = { bank_reference: bankReference }
    }
    matchMutation.mutate(mutateData, {
      onSuccess: () => {
        toast.success(t('reconciliation.messages.itemMatched'))
        setBankReference('')
        setMatchingPaymentId(null)
      },
      onError: () => {
        toast.error(t('errorMessages.generic'))
      },
    })
  }

  const handleUnmatch = (paymentId: string) => {
    unmatchMutation.mutate(
      { reconciliationId, paymentId },
      {
        onSuccess: () => {
          toast.success(t('reconciliation.messages.itemUnmatched'))
        },
        onError: () => {
          toast.error(t('errorMessages.generic'))
        },
      }
    )
  }

  const handleComplete = () => {
    completeMutation.mutate(reconciliationId, {
      onSuccess: () => {
        toast.success(t('reconciliation.messages.completed'))
        setShowCompleteConfirm(false)
        onClose()
      },
      onError: () => {
        toast.error(t('errorMessages.generic'))
      },
    })
  }

  const handleCancel = () => {
    cancelMutation.mutate(reconciliationId, {
      onSuccess: () => {
        toast.success(t('reconciliation.messages.cancelled'))
        setShowCancelConfirm(false)
        onClose()
      },
      onError: () => {
        toast.error(t('errorMessages.generic'))
      },
    })
  }

  const backButton = (
    <button
      type="button"
      onClick={onClose}
      className={cn('inline-flex items-center gap-1 text-sm', textColors.tertiary, textColors.hoverPrimary)}
    >
      <ArrowLeft className="h-4 w-4" />
      {t('back')}
    </button>
  )

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className={cn('h-8 w-8 animate-spin rounded-full border-4 border-t-transparent', borderColors.primary)} />
      </div>
    )
  }

  if (error || !reconciliation) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('reconciliation.title')} breadcrumb={backButton} />
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errorMessages.generic')}
        </div>
      </div>
    )
  }

  const isEditable = reconciliation.status === 'draft'
  const hasDifference = bccomp(reconciliation.difference, '0') !== 0

  return (
    <div className="space-y-6">
      {/* Header */}
      <PageHeader
        title={reconciliation.repository_name ?? t('reconciliation.title')}
        breadcrumb={backButton}
        subtitle={`${t('reconciliation.statementDate')}: ${formatDate(reconciliation.statement_date)}`}
        actions={<StatusBadge tone={statusTone(reconciliation.status)}>{t(`reconciliation.status.${reconciliation.status}`)}</StatusBadge>}
      />

      {/* Summary cards */}
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div className={cn('rounded-lg p-4', tokens.table.header)}>
          <p className={cn('text-xs', textColors.tertiary)}>{t('reconciliation.openingBalance')}</p>
          <p className={cn('text-lg font-semibold tabular-nums', textColors.primary)}>{formatCurrency(reconciliation.opening_balance)}</p>
        </div>
        <div className={cn('rounded-lg p-4', tokens.table.header)}>
          <p className={cn('text-xs', textColors.tertiary)}>{t('reconciliation.statementBalance')}</p>
          <p className={cn('text-lg font-semibold tabular-nums', textColors.primary)}>{formatCurrency(reconciliation.statement_balance)}</p>
        </div>
        <div className={cn('rounded-lg p-4', tokens.table.header)}>
          <p className={cn('text-xs', textColors.tertiary)}>{t('reconciliation.closingBalance')}</p>
          <p className={cn('text-lg font-semibold tabular-nums', textColors.primary)}>{formatCurrency(reconciliation.closing_balance)}</p>
        </div>
        <div className={cn('rounded-lg p-4', hasDifference ? tokens.alert.error : tokens.alert.success)}>
          <p className={cn('text-xs', textColors.tertiary)}>{t('reconciliation.difference')}</p>
          <p className={cn('text-lg font-semibold tabular-nums', hasDifference ? textColors.error : textColors.success)}>
            {formatCurrency(reconciliation.difference)}
          </p>
        </div>
      </div>

      {/* Summary stats */}
      {summary && (
        <div className={cn('flex gap-6 rounded-lg border bg-white p-4', borderColors.light)}>
          <div>
            <p className={cn('text-sm', textColors.tertiary)}>{t('reconciliation.summary.matchedCount')}</p>
            <p className={cn('text-xl font-bold tabular-nums', textColors.success)}>{summary.matched_count}</p>
            <p className={cn('text-xs tabular-nums', textColors.disabled)}>{formatCurrency(summary.matched_total)}</p>
          </div>
          <div className={cn('border-s ps-6', borderColors.light)}>
            <p className={cn('text-sm', textColors.tertiary)}>{t('reconciliation.summary.unmatchedCount')}</p>
            <p className={cn('text-xl font-bold tabular-nums', textColors.warningDark)}>{summary.unmatched_count}</p>
            <p className={cn('text-xs tabular-nums', textColors.disabled)}>{formatCurrency(summary.unmatched_total)}</p>
          </div>
          <div className="ms-auto">
            {summary.can_complete ? (
              <StatusBadge tone="success">
                {t('reconciliation.summary.canComplete')}
              </StatusBadge>
            ) : (
              <StatusBadge tone="warning">
                {t('reconciliation.summary.cannotComplete')}
              </StatusBadge>
            )}
          </div>
        </div>
      )}

      {/* Warning if difference */}
      {hasDifference && isEditable && (
        <div className={cn('flex items-center gap-2 rounded-lg p-4', tokens.alert.warning)}>
          <AlertCircle className="h-5 w-5" />
          {t('reconciliation.messages.differenceWarning', { amount: formatCurrency(reconciliation.difference) })}
        </div>
      )}

      {/* Items table — two-pane matching UI, kept inline + tokenized */}
      <div className={cn('rounded-lg border', borderColors.light)}>
        <div className={cn('border-b px-4 py-3', borderColors.light, tokens.table.header)}>
          <h2 className={cn('font-medium', textColors.primary)}>{t('reconciliation.items.title')}</h2>
        </div>

        <div className={cn('divide-y', borderColors.divideLight)}>
          {reconciliation.items?.length === 0 ? (
            <div className={cn('p-8 text-center', textColors.tertiary)}>
              {t('noData')}
            </div>
          ) : (
            reconciliation.items?.map((item) => (
              <ReconciliationItemRow
                key={item.id}
                item={item}
                isEditable={isEditable}
                bankReference={bankReference}
                matchingPaymentId={matchingPaymentId}
                onChangeBankReference={setBankReference}
                onStartMatch={setMatchingPaymentId}
                onConfirmMatch={handleMatch}
                onCancelMatch={() => {
                  setMatchingPaymentId(null)
                  setBankReference('')
                }}
                onUnmatch={handleUnmatch}
                matchPending={matchMutation.isPending}
                unmatchPending={unmatchMutation.isPending}
              />
            ))
          )}
        </div>
      </div>

      {/* Actions */}
      {isEditable && (
        <div className="flex justify-end gap-3">
          <Button variant="danger" onClick={() => { setShowCancelConfirm(true); }}>
            {t('reconciliation.actions.cancel')}
          </Button>
          <Button
            onClick={() => { setShowCompleteConfirm(true); }}
            disabled={!summary?.can_complete}
          >
            {t('reconciliation.actions.complete')}
          </Button>
        </div>
      )}

      {/* Complete confirmation modal */}
      <Modal
        isOpen={showCompleteConfirm}
        onClose={() => { setShowCompleteConfirm(false); }}
        size="sm"
        title={t('reconciliation.confirmations.complete.title')}
      >
        <ModalContent>
          <p className={textColors.tertiary}>
            {t('reconciliation.confirmations.complete.message')}
          </p>
        </ModalContent>
        <ModalFooter>
          <Button variant="secondary" onClick={() => { setShowCompleteConfirm(false); }}>
            {t('cancel')}
          </Button>
          <Button onClick={handleComplete} disabled={completeMutation.isPending}>
            {completeMutation.isPending ? t('loading') : t('reconciliation.confirmations.complete.confirm')}
          </Button>
        </ModalFooter>
      </Modal>

      {/* Cancel confirmation modal */}
      <Modal
        isOpen={showCancelConfirm}
        onClose={() => { setShowCancelConfirm(false); }}
        size="sm"
        title={t('reconciliation.confirmations.cancel.title')}
      >
        <ModalContent>
          <p className={textColors.tertiary}>
            {t('reconciliation.confirmations.cancel.message')}
          </p>
        </ModalContent>
        <ModalFooter>
          <Button variant="secondary" onClick={() => { setShowCancelConfirm(false); }}>
            {t('cancel')}
          </Button>
          <Button variant="danger" onClick={handleCancel} disabled={cancelMutation.isPending}>
            {cancelMutation.isPending ? t('loading') : t('reconciliation.confirmations.cancel.confirm')}
          </Button>
        </ModalFooter>
      </Modal>
    </div>
  )
}

// Main page component
export function BankReconciliationPage() {
  const { t } = useTranslation()
  const [searchParams, setSearchParams] = useSearchParams()
  const selectedId = searchParams.get('id')

  const [statusFilter, setStatusFilter] = useState<string>('')
  const [showStartModal, setShowStartModal] = useState(false)

  const { data: reconciliations, isLoading, error } = useReconciliations(
    statusFilter ? { status: statusFilter } : undefined
  )
  const startMutation = useStartReconciliation()

  const handleStartReconciliation = (data: {
    repository_id: string
    statement_date: string
    opening_balance?: string
    statement_balance: string
    notes?: string
  }) => {
    startMutation.mutate(data, {
      onSuccess: (result) => {
        toast.success(t('reconciliation.messages.started'))
        setShowStartModal(false)
        setSearchParams({ id: result.id })
      },
      onError: () => {
        toast.error(t('errorMessages.generic'))
      },
    })
  }

  // If viewing a specific reconciliation
  if (selectedId) {
    return (
      <div className={cn('min-h-screen px-6 py-8', tokens.table.header)}>
        <div className="mx-auto max-w-5xl">
          <ReconciliationDetail
            reconciliationId={selectedId}
            onClose={() => { setSearchParams({}); }}
          />
        </div>
      </div>
    )
  }

  const columns: DataTableColumn<BankReconciliation>[] = [
    {
      key: 'date',
      header: t('reconciliation.table.date'),
      render: (rec) => (
        <div className={cn('flex items-center gap-2', textColors.primary)}>
          <Calendar className={cn('h-4 w-4', textColors.disabled)} />
          {formatDate(rec.statement_date)}
        </div>
      ),
    },
    {
      key: 'repository',
      header: t('reconciliation.table.repository'),
      cellClassName: 'font-medium',
      render: (rec) => rec.repository_name,
    },
    {
      key: 'statementBalance',
      header: t('reconciliation.table.statementBalance'),
      numeric: true,
      render: (rec) => formatCurrency(rec.statement_balance),
    },
    {
      key: 'difference',
      header: t('reconciliation.table.difference'),
      numeric: true,
      cellClassName: 'font-medium',
      render: (rec) => {
        const hasDiff = bccomp(rec.difference, '0') !== 0
        return (
          <span className={hasDiff ? textColors.error : textColors.success}>
            {formatCurrency(rec.difference)}
          </span>
        )
      },
    },
    {
      key: 'status',
      header: t('reconciliation.table.status'),
      render: (rec) => (
        <StatusBadge tone={statusTone(rec.status)}>
          {t(`reconciliation.status.${rec.status}`)}
        </StatusBadge>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('actionsLabel')}</span>,
      align: 'right',
      render: (rec) => (
        <Button
          variant="ghost"
          onClick={() => { setSearchParams({ id: rec.id }); }}
        >
          <Eye className="h-4 w-4" />
          {rec.status === 'draft'
            ? t('reconciliation.continue')
            : t('reconciliation.viewDetails')}
        </Button>
      ),
    },
  ]

  const backLink = (
    <Link
      to="/finance"
      className={cn('inline-flex items-center gap-1 text-sm', textColors.tertiary, textColors.hoverPrimary)}
    >
      <ArrowLeft className="h-4 w-4" />
      {t('back')}
    </Link>
  )

  // List view
  return (
    <div className={cn('min-h-screen px-6 py-8', tokens.table.header)}>
      <div className="mx-auto max-w-5xl">
        {/* Header */}
        <PageHeader
          title={t('reconciliation.title')}
          breadcrumb={backLink}
          subtitle={t('reconciliation.subtitle')}
          actions={
            <Button onClick={() => { setShowStartModal(true); }}>
              <Plus className="h-4 w-4" />
              {t('reconciliation.startNew')}
            </Button>
          }
        />

        {/* Filters */}
        <div className="mb-4">
          <FilterTabs
            value={statusFilter}
            onChange={setStatusFilter}
            tabs={[
              { value: '', label: t('reconciliation.filters.all') },
              { value: 'draft', label: t('reconciliation.filters.inProgress') },
              { value: 'completed', label: t('reconciliation.filters.completed') },
            ]}
          />
        </div>

        {/* Content */}
        {error ? (
          <div className={cn(tokens.alert.base, tokens.alert.error)}>
            {t('errorMessages.generic')}
          </div>
        ) : !isLoading && (reconciliations?.length ?? 0) === 0 ? (
          <div className="rounded-lg bg-white p-12 text-center shadow-sm">
            <Building2 className={cn('mx-auto h-12 w-12', textColors.disabled)} />
            <h2 className={cn('mt-4 text-lg font-medium', textColors.primary)}>
              {t('reconciliation.empty.title')}
            </h2>
            <p className={cn('mt-2', textColors.tertiary)}>
              {t('reconciliation.empty.description')}
            </p>
            <Button onClick={() => { setShowStartModal(true); }} className="mt-6">
              <Plus className="h-4 w-4" />
              {t('reconciliation.startNew')}
            </Button>
          </div>
        ) : (
          <div className="overflow-hidden rounded-lg bg-white shadow-sm">
            <DataTable
              columns={columns}
              data={reconciliations ?? []}
              keyExtractor={(rec) => rec.id}
              isLoading={isLoading}
            />
          </div>
        )}

        {/* Start modal */}
        <StartReconciliationModal
          isOpen={showStartModal}
          onClose={() => { setShowStartModal(false); }}
          onSubmit={handleStartReconciliation}
          isLoading={startMutation.isPending}
        />
      </div>
    </div>
  )
}
