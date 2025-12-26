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
  XCircle,
  Eye,
} from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '../../components/atoms/Button/Button'
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
import type { BankReconciliationItem, ReconciliationStatus } from '@/types/treasury'

// Format currency amount
function formatCurrency(amount: string | number, currency = 'USD'): string {
  const num = typeof amount === 'string' ? parseFloat(amount) : amount
  return new Intl.NumberFormat('fr-TN', {
    style: 'currency',
    currency,
    minimumFractionDigits: 2,
  }).format(num)
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

// Status badge component
function StatusBadge({ status }: { status: ReconciliationStatus }) {
  const { t } = useTranslation()

  const variants: Record<ReconciliationStatus, { bg: string; text: string; icon: React.ReactNode }> = {
    draft: {
      bg: 'bg-amber-100',
      text: 'text-amber-800',
      icon: <AlertCircle className="h-3.5 w-3.5" />,
    },
    completed: {
      bg: 'bg-emerald-100',
      text: 'text-emerald-800',
      icon: <CheckCircle2 className="h-3.5 w-3.5" />,
    },
    cancelled: {
      bg: 'bg-gray-100',
      text: 'text-gray-800',
      icon: <XCircle className="h-3.5 w-3.5" />,
    },
  }

  const variant = variants[status] || variants.draft

  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${variant.bg} ${variant.text}`}>
      {variant.icon}
      {t(`reconciliation.status.${status}`)}
    </span>
  )
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
  onSubmit: (data: { repository_id: string; statement_date: string; statement_balance: string; notes?: string }) => void
  isLoading: boolean
}) {
  const { t } = useTranslation()
  const { data: repositories, isLoading: loadingRepositories } = usePaymentRepositories()

  const [repositoryId, setRepositoryId] = useState('')
  const [statementDate, setStatementDate] = useState(new Date().toISOString().split('T')[0])
  const [statementBalance, setStatementBalance] = useState('')
  const [notes, setNotes] = useState('')

  if (!isOpen) return null

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!repositoryId || !statementDate || !statementBalance) return
    const data: { repository_id: string; statement_date: string; statement_balance: string; notes?: string } = {
      repository_id: repositoryId,
      statement_date: statementDate,
      statement_balance: statementBalance,
    }
    if (notes) {
      data.notes = notes
    }
    onSubmit(data)
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 className="mb-4 text-lg font-semibold">{t('reconciliation.startNew')}</h2>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              {t('reconciliation.repository')}
            </label>
            <select
              value={repositoryId}
              onChange={(e) => { setRepositoryId(e.target.value); }}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              required
              disabled={loadingRepositories}
            >
              <option value="">{t('reconciliation.selectRepository')}</option>
              {repositories?.map((repo) => (
                <option key={repo.id} value={repo.id}>
                  {repo.name} ({formatCurrency(repo.balance, repo.currency)})
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              {t('reconciliation.statementDate')}
            </label>
            <input
              type="date"
              value={statementDate}
              onChange={(e) => { setStatementDate(e.target.value); }}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              required
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              {t('reconciliation.statementBalance')}
            </label>
            <input
              type="number"
              step="0.01"
              value={statementBalance}
              onChange={(e) => { setStatementBalance(e.target.value); }}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              placeholder="0.00"
              required
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-gray-700">
              {t('fields.notes')}
            </label>
            <textarea
              value={notes}
              onChange={(e) => { setNotes(e.target.value); }}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              rows={2}
            />
          </div>

          <div className="flex justify-end gap-3 pt-2">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t('cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || !repositoryId}>
              {isLoading ? t('loading') : t('reconciliation.startNew')}
            </Button>
          </div>
        </form>
      </div>
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
        toast.error(t('error.generic'))
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
          toast.error(t('error.generic'))
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
        toast.error(t('error.generic'))
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
        toast.error(t('error.generic'))
      },
    })
  }

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
      </div>
    )
  }

  if (error || !reconciliation) {
    return (
      <div className="rounded-lg bg-red-50 p-4 text-red-700">
        {t('error.generic')}
      </div>
    )
  }

  const isEditable = reconciliation.status === 'draft'
  const difference = parseFloat(reconciliation.difference)
  const hasDifference = Math.abs(difference) > 0.001

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <button
            onClick={onClose}
            className="mb-2 flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('back')}
          </button>
          <h2 className="text-xl font-semibold">{reconciliation.repository_name}</h2>
          <p className="text-sm text-gray-500">
            {t('reconciliation.statementDate')}: {formatDate(reconciliation.statement_date)}
          </p>
        </div>
        <StatusBadge status={reconciliation.status} />
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div className="rounded-lg bg-gray-50 p-4">
          <p className="text-xs text-gray-500">{t('reconciliation.openingBalance')}</p>
          <p className="text-lg font-semibold">{formatCurrency(reconciliation.opening_balance)}</p>
        </div>
        <div className="rounded-lg bg-gray-50 p-4">
          <p className="text-xs text-gray-500">{t('reconciliation.statementBalance')}</p>
          <p className="text-lg font-semibold">{formatCurrency(reconciliation.statement_balance)}</p>
        </div>
        <div className="rounded-lg bg-gray-50 p-4">
          <p className="text-xs text-gray-500">{t('reconciliation.closingBalance')}</p>
          <p className="text-lg font-semibold">{formatCurrency(reconciliation.closing_balance)}</p>
        </div>
        <div className={`rounded-lg p-4 ${hasDifference ? 'bg-red-50' : 'bg-emerald-50'}`}>
          <p className="text-xs text-gray-500">{t('reconciliation.difference')}</p>
          <p className={`text-lg font-semibold ${hasDifference ? 'text-red-600' : 'text-emerald-600'}`}>
            {formatCurrency(reconciliation.difference)}
          </p>
        </div>
      </div>

      {/* Summary stats */}
      {summary && (
        <div className="flex gap-6 rounded-lg border border-gray-200 bg-white p-4">
          <div>
            <p className="text-sm text-gray-500">{t('reconciliation.summary.matchedCount')}</p>
            <p className="text-xl font-bold text-emerald-600">{summary.matched_count}</p>
            <p className="text-xs text-gray-400">{formatCurrency(summary.matched_total)}</p>
          </div>
          <div className="border-s border-gray-200 ps-6">
            <p className="text-sm text-gray-500">{t('reconciliation.summary.unmatchedCount')}</p>
            <p className="text-xl font-bold text-amber-600">{summary.unmatched_count}</p>
            <p className="text-xs text-gray-400">{formatCurrency(summary.unmatched_total)}</p>
          </div>
          <div className="ms-auto">
            {summary.can_complete ? (
              <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-3 py-1 text-sm text-emerald-700">
                <CheckCircle2 className="h-4 w-4" />
                {t('reconciliation.summary.canComplete')}
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-3 py-1 text-sm text-amber-700">
                <AlertCircle className="h-4 w-4" />
                {t('reconciliation.summary.cannotComplete')}
              </span>
            )}
          </div>
        </div>
      )}

      {/* Warning if difference */}
      {hasDifference && isEditable && (
        <div className="flex items-center gap-2 rounded-lg bg-amber-50 p-4 text-amber-800">
          <AlertCircle className="h-5 w-5" />
          {t('reconciliation.messages.differenceWarning', { amount: formatCurrency(reconciliation.difference) })}
        </div>
      )}

      {/* Items table */}
      <div className="rounded-lg border border-gray-200">
        <div className="border-b border-gray-200 bg-gray-50 px-4 py-3">
          <h3 className="font-medium">{t('reconciliation.items.title')}</h3>
        </div>

        <div className="divide-y divide-gray-100">
          {reconciliation.items?.length === 0 ? (
            <div className="p-8 text-center text-gray-500">
              {t('noData')}
            </div>
          ) : (
            reconciliation.items?.map((item: BankReconciliationItem) => (
              <div
                key={item.id}
                className={`flex items-center gap-4 p-4 ${
                  item.is_matched ? 'bg-emerald-50/50' : ''
                }`}
              >
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{item.payment_reference}</span>
                    {item.is_matched && (
                      <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                    )}
                  </div>
                  <div className="mt-1 flex gap-4 text-sm text-gray-500">
                    <span>{formatDate(item.payment_date)}</span>
                    {item.partner_name && <span>{item.partner_name}</span>}
                    {item.bank_reference && (
                      <span className="text-blue-600">{item.bank_reference}</span>
                    )}
                  </div>
                </div>

                <div className="text-end">
                  <p className="font-semibold">{formatCurrency(item.payment_amount)}</p>
                </div>

                {isEditable && (
                  <div className="flex items-center gap-2">
                    {item.is_matched ? (
                      <Button
                        variant="secondary"
                        onClick={() => { handleUnmatch(item.payment_id); }}
                        disabled={unmatchMutation.isPending}
                      >
                        <X className="h-4 w-4" />
                        {t('reconciliation.items.unmatch')}
                      </Button>
                    ) : matchingPaymentId === item.payment_id ? (
                      <div className="flex items-center gap-2">
                        <input
                          type="text"
                          value={bankReference}
                          onChange={(e) => { setBankReference(e.target.value); }}
                          placeholder={t('reconciliation.items.bankReferencePlaceholder')}
                          className="w-40 rounded border border-gray-300 px-2 py-1 text-sm"
                        />
                        <Button
                          onClick={() => { handleMatch(item.payment_id); }}
                          disabled={matchMutation.isPending}
                        >
                          <Check className="h-4 w-4" />
                        </Button>
                        <Button
                          variant="secondary"
                          onClick={() => {
                            setMatchingPaymentId(null)
                            setBankReference('')
                          }}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    ) : (
                      <Button
                        variant="secondary"
                        onClick={() => { setMatchingPaymentId(item.payment_id); }}
                      >
                        <Check className="h-4 w-4" />
                        {t('reconciliation.items.match')}
                      </Button>
                    )}
                  </div>
                )}
              </div>
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
      {showCompleteConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <h3 className="mb-2 text-lg font-semibold">
              {t('reconciliation.confirmations.complete.title')}
            </h3>
            <p className="mb-4 text-gray-600">
              {t('reconciliation.confirmations.complete.message')}
            </p>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={() => { setShowCompleteConfirm(false); }}>
                {t('cancel')}
              </Button>
              <Button onClick={handleComplete} disabled={completeMutation.isPending}>
                {completeMutation.isPending ? t('loading') : t('reconciliation.confirmations.complete.confirm')}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Cancel confirmation modal */}
      {showCancelConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
            <h3 className="mb-2 text-lg font-semibold">
              {t('reconciliation.confirmations.cancel.title')}
            </h3>
            <p className="mb-4 text-gray-600">
              {t('reconciliation.confirmations.cancel.message')}
            </p>
            <div className="flex justify-end gap-3">
              <Button variant="secondary" onClick={() => { setShowCancelConfirm(false); }}>
                {t('cancel')}
              </Button>
              <Button variant="danger" onClick={handleCancel} disabled={cancelMutation.isPending}>
                {cancelMutation.isPending ? t('loading') : t('reconciliation.confirmations.cancel.confirm')}
              </Button>
            </div>
          </div>
        </div>
      )}
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
        toast.error(t('error.generic'))
      },
    })
  }

  // If viewing a specific reconciliation
  if (selectedId) {
    return (
      <div className="min-h-screen bg-gray-50 px-6 py-8">
        <div className="mx-auto max-w-5xl">
          <ReconciliationDetail
            reconciliationId={selectedId}
            onClose={() => { setSearchParams({}); }}
          />
        </div>
      </div>
    )
  }

  // List view
  return (
    <div className="min-h-screen bg-gray-50 px-6 py-8">
      <div className="mx-auto max-w-5xl">
        {/* Header */}
        <div className="mb-6 flex items-start justify-between">
          <div>
            <Link
              to="/settings"
              className="mb-2 flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700"
            >
              <ArrowLeft className="h-4 w-4" />
              {t('back')}
            </Link>
            <h1 className="text-2xl font-bold">{t('reconciliation.title')}</h1>
            <p className="text-gray-500">{t('reconciliation.subtitle')}</p>
          </div>
          <Button onClick={() => { setShowStartModal(true); }}>
            <Plus className="h-4 w-4" />
            {t('reconciliation.startNew')}
          </Button>
        </div>

        {/* Filters */}
        <div className="mb-4 flex gap-2">
          <button
            onClick={() => { setStatusFilter(''); }}
            className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
              statusFilter === ''
                ? 'bg-blue-100 text-blue-700'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
          >
            {t('reconciliation.filters.all')}
          </button>
          <button
            onClick={() => { setStatusFilter('draft'); }}
            className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
              statusFilter === 'draft'
                ? 'bg-amber-100 text-amber-700'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
          >
            {t('reconciliation.filters.inProgress')}
          </button>
          <button
            onClick={() => { setStatusFilter('completed'); }}
            className={`rounded-full px-4 py-1.5 text-sm font-medium transition-colors ${
              statusFilter === 'completed'
                ? 'bg-emerald-100 text-emerald-700'
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
            }`}
          >
            {t('reconciliation.filters.completed')}
          </button>
        </div>

        {/* Content */}
        {isLoading ? (
          <div className="flex h-64 items-center justify-center">
            <div className="h-8 w-8 animate-spin rounded-full border-4 border-blue-500 border-t-transparent" />
          </div>
        ) : error ? (
          <div className="rounded-lg bg-red-50 p-4 text-red-700">
            {t('error.generic')}
          </div>
        ) : reconciliations?.length === 0 ? (
          <div className="rounded-lg bg-white p-12 text-center shadow-sm">
            <Building2 className="mx-auto h-12 w-12 text-gray-300" />
            <h3 className="mt-4 text-lg font-medium text-gray-900">
              {t('reconciliation.empty.title')}
            </h3>
            <p className="mt-2 text-gray-500">
              {t('reconciliation.empty.description')}
            </p>
            <Button onClick={() => { setShowStartModal(true); }} className="mt-6">
              <Plus className="h-4 w-4" />
              {t('reconciliation.startNew')}
            </Button>
          </div>
        ) : (
          <div className="overflow-hidden rounded-lg bg-white shadow-sm">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('reconciliation.table.date')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('reconciliation.table.repository')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('reconciliation.table.statementBalance')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('reconciliation.table.difference')}
                  </th>
                  <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('reconciliation.table.status')}
                  </th>
                  <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                    {t('actionsLabel')}
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 bg-white">
                {reconciliations?.map((rec) => {
                  const diff = parseFloat(rec.difference)
                  const hasDiff = Math.abs(diff) > 0.001

                  return (
                    <tr key={rec.id} className="hover:bg-gray-50">
                      <td className="whitespace-nowrap px-6 py-4">
                        <div className="flex items-center gap-2">
                          <Calendar className="h-4 w-4 text-gray-400" />
                          {formatDate(rec.statement_date)}
                        </div>
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 font-medium">
                        {rec.repository_name}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-end">
                        {formatCurrency(rec.statement_balance)}
                      </td>
                      <td className={`whitespace-nowrap px-6 py-4 text-end font-medium ${
                        hasDiff ? 'text-red-600' : 'text-emerald-600'
                      }`}>
                        {formatCurrency(rec.difference)}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        <StatusBadge status={rec.status} />
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-end">
                        <Button
                          variant="ghost"
                          onClick={() => { setSearchParams({ id: rec.id }); }}
                        >
                          <Eye className="h-4 w-4" />
                          {rec.status === 'draft'
                            ? t('reconciliation.continue')
                            : t('reconciliation.viewDetails')}
                        </Button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
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
