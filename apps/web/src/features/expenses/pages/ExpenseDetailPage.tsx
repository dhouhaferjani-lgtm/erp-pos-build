import { Link, useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit, Trash2, FileText } from 'lucide-react'
import { format } from 'date-fns'
import { cn } from '@/lib/utils'
import { tokens, textColors } from '@/lib/designTokens'
import { Button } from '@/components/atoms/Button'
import {
  StatusBadge,
  statusTone,
  type StatusTone,
} from '@/components/atoms/StatusBadge'
import { PageHeader } from '@/components/molecules/PageHeader'
import { useExpense, useDeleteExpense, usePostExpense } from '../hooks/useExpenses'
import { DocumentAttachments } from '../../documents/components/DocumentAttachments'

/**
 * Expense-status tone overrides for the shared StatusBadge. The built-in
 * statusTone map already covers draft→pending, approved→success,
 * rejected/cancelled→danger; `posted` defaults to neutral but expenses treat a
 * posted expense as a finalized/success state, so override it to `success`.
 */
const statusToneOverrides: Record<string, StatusTone> = {
  posted: 'success',
}

/**
 * Page: Expense detail
 *
 * Display full expense details with the ability to edit, delete, post, and manage attachments.
 */
export function ExpenseDetailPage() {
  const { t } = useTranslation(['expenses', 'common'])
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const { data: expense, isLoading } = useExpense(id || '')
  const deleteExpense = useDeleteExpense()
  const postExpense = usePostExpense()

  const handleDelete = async () => {
    if (
      !confirm(t('expenses:confirmDelete'))
    ) {
      return
    }

    try {
      await deleteExpense.mutateAsync(id || '')
      navigate('/expenses')
    } catch {
      // Error handling is done in hooks
    }
  }

  const handlePost = async () => {
    if (
      !confirm(t('expenses:confirmPost'))
    ) {
      return
    }

    try {
      await postExpense.mutateAsync(id || '')
    } catch {
      // Error handling is done in hooks
    }
  }

  if (isLoading) {
    return (
      <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="animate-pulse space-y-4">
          <div className={cn('h-8 w-48 rounded', tokens.table.header)} />
          <div className={cn('h-96 rounded-lg', tokens.table.header)} />
        </div>
      </div>
    )
  }

  const backLink = (
    <Link
      to="/expenses"
      className={cn(
        'inline-flex items-center gap-2 text-sm',
        textColors.tertiary,
        textColors.hoverPrimary,
      )}
    >
      <ArrowLeft className="h-4 w-4" />
      {t('common:back')}
    </Link>
  )

  if (!expense) {
    return (
      <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="text-center">
          <p className={textColors.tertiary}>{t('expenses:errors.notFound')}</p>
          <div className="mt-4 inline-flex justify-center">{backLink}</div>
        </div>
      </div>
    )
  }

  const isDraft = expense.status === 'draft'
  const isPosted = expense.status === 'posted'

  return (
    <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <PageHeader
        title={expense.document_number}
        breadcrumb={backLink}
        subtitle={expense.metadata?.vendor_name ?? t('expenses:noVendor')}
        actions={
          <>
            {isDraft && (
              <>
                <Link to={`/expenses/${expense.id}`}>
                  <Button variant="secondary">
                    <Edit className="me-2 h-4 w-4" />
                    {t('common:edit')}
                  </Button>
                </Link>
                <Button
                  variant="danger"
                  onClick={handleDelete}
                  disabled={deleteExpense.isPending}
                >
                  <Trash2 className="me-2 h-4 w-4" />
                  {t('common:delete')}
                </Button>
              </>
            )}
            {!isPosted && (
              <Button
                variant="primary"
                onClick={handlePost}
                disabled={postExpense.isPending}
              >
                <FileText className="me-2 h-4 w-4" />
                {postExpense.isPending ? t('common:processing') : t('expenses:postExpense')}
              </Button>
            )}
          </>
        }
      />

      {/* Status Badge */}
      <div className="mb-6">
        <StatusBadge tone={statusTone(expense.status, statusToneOverrides)}>
          {t(`expenses:status.${expense.status}`)}
        </StatusBadge>
      </div>

      {/* Expense Details */}
      <div className="space-y-6">
        {/* Basic Information */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>
            {t('expenses:form.vendorInfo')}
          </h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:vendorName')}
              </dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {expense.metadata?.vendor_name ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:receiptNumber')}
              </dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {expense.metadata?.receipt_number ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:category')}
              </dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {expense.metadata?.category?.name ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:amount')}
              </dt>
              <dd className={cn('mt-1 text-lg font-semibold tabular-nums', textColors.primary)}>
                {expense.total} {expense.currency}
              </dd>
            </div>
          </div>
        </div>

        {/* Payment Details */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>
            {t('expenses:form.paymentDetails')}
          </h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:paymentMethod')}
              </dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {expense.metadata?.payment_method?.name ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:paymentRepository')}
              </dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {expense.metadata?.payment_repository?.name ?? '-'}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:date')}
              </dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {format(new Date(expense.document_date), 'PPP')}
              </dd>
            </div>
            <div>
              <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                {t('expenses:form.paymentDate')}
              </dt>
              <dd className={cn('mt-1 text-sm', textColors.primary)}>
                {expense.metadata?.payment_date
                  ? format(new Date(expense.metadata.payment_date), 'PPP')
                  : '-'}
              </dd>
            </div>
          </div>
          {expense.metadata?.is_paid && (
            <div className="mt-4">
              <StatusBadge tone="success">{t('expenses:paid')}</StatusBadge>
            </div>
          )}
        </div>

        {/* Notes */}
        {(expense.notes || expense.internal_notes) && (
          <div className={tokens.card.base}>
            <h2 className={cn(tokens.heading.section, 'mb-4')}>
              {t('expenses:notes')}
            </h2>
            {expense.notes && (
              <div className="mb-4">
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                  {t('expenses:form.notes')}
                </dt>
                <dd className={cn('mt-1 whitespace-pre-wrap text-sm', textColors.primary)}>
                  {expense.notes}
                </dd>
              </div>
            )}
            {expense.internal_notes && (
              <div>
                <dt className={cn('text-sm font-medium', textColors.tertiary)}>
                  {t('expenses:form.internalNotes')}
                </dt>
                <dd className={cn('mt-1 whitespace-pre-wrap text-sm', textColors.primary)}>
                  {expense.internal_notes}
                </dd>
              </div>
            )}
          </div>
        )}

        {/* Attachments */}
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>
            {t('expenses:attachments')}
          </h2>
          <DocumentAttachments documentId={expense.id} readOnly={isPosted} />
        </div>
      </div>
    </div>
  )
}
