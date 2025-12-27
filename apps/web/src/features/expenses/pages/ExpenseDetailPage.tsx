import { Link, useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Edit, Trash2, FileText } from 'lucide-react'
import { format } from 'date-fns'
import { useExpense, useDeleteExpense, usePostExpense } from '../hooks/useExpenses'
import { DocumentAttachments } from '../../documents/components/DocumentAttachments'

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
    } catch (error) {
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
    } catch (error) {
      // Error handling is done in hooks
    }
  }

  if (isLoading) {
    return (
      <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="animate-pulse space-y-4">
          <div className="h-8 w-48 rounded bg-gray-200 dark:bg-gray-700" />
          <div className="h-96 rounded-lg bg-gray-200 dark:bg-gray-700" />
        </div>
      </div>
    )
  }

  if (!expense) {
    return (
      <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="text-center">
          <p className="text-gray-500 dark:text-gray-400">{t('expenses:errors.notFound')}</p>
          <Link
            to="/expenses"
            className="mt-4 inline-flex items-center gap-2 text-primary-600 hover:text-primary-700"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('common:back')}
          </Link>
        </div>
      </div>
    )
  }

  const isDraft = expense.status === 'draft'
  const isPosted = expense.status === 'posted'

  return (
    <div className="mx-auto max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="mb-6">
        <Link
          to="/expenses"
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100 mb-4"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:back')}
        </Link>

        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-gray-100">
              {expense.document_number}
            </h1>
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              {expense.metadata?.vendor_name || t('expenses:noVendor')}
            </p>
          </div>

          <div className="flex items-center gap-3">
            {isDraft && (
              <>
                <Link
                  to={`/expenses/${expense.id}`}
                  className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
                >
                  <Edit className="h-4 w-4" />
                  {t('common:edit')}
                </Link>
                <button
                  onClick={handleDelete}
                  disabled={deleteExpense.isPending}
                  className="inline-flex items-center gap-2 rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-700 shadow-sm hover:bg-red-50 disabled:opacity-50 dark:border-red-600 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-900/20"
                >
                  <Trash2 className="h-4 w-4" />
                  {t('common:delete')}
                </button>
              </>
            )}
            {!isPosted && (
              <button
                onClick={handlePost}
                disabled={postExpense.isPending}
                className="inline-flex items-center gap-2 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 disabled:opacity-50"
              >
                <FileText className="h-4 w-4" />
                {postExpense.isPending ? t('common:processing') : t('expenses:postExpense')}
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Status Badge */}
      <div className="mb-6">
        <span
          className={`inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ${
            isPosted
              ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400'
              : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300'
          }`}
        >
          {t(`expenses:status.${expense.status}`)}
        </span>
      </div>

      {/* Expense Details */}
      <div className="space-y-6">
        {/* Basic Information */}
        <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
          <h2 className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            {t('expenses:form.vendorInfo')}
          </h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:vendorName')}
              </label>
              <p className="mt-1 text-sm text-gray-900 dark:text-gray-100">
                {expense.metadata?.vendor_name || '-'}
              </p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:receiptNumber')}
              </label>
              <p className="mt-1 text-sm text-gray-900 dark:text-gray-100">
                {expense.metadata?.receipt_number || '-'}
              </p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:category')}
              </label>
              <p className="mt-1 text-sm text-gray-900 dark:text-gray-100">
                {expense.metadata?.category?.name || '-'}
              </p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:amount')}
              </label>
              <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">
                {expense.total} {expense.currency}
              </p>
            </div>
          </div>
        </div>

        {/* Payment Details */}
        <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
          <h2 className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            {t('expenses:form.paymentDetails')}
          </h2>
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:paymentMethod')}
              </label>
              <p className="mt-1 text-sm text-gray-900 dark:text-gray-100">
                {expense.metadata?.payment_method?.name || '-'}
              </p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:paymentRepository')}
              </label>
              <p className="mt-1 text-sm text-gray-900 dark:text-gray-100">
                {expense.metadata?.payment_repository?.name || '-'}
              </p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:date')}
              </label>
              <p className="mt-1 text-sm text-gray-900 dark:text-gray-100">
                {format(new Date(expense.document_date), 'PPP')}
              </p>
            </div>
            <div>
              <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                {t('expenses:form.paymentDate')}
              </label>
              <p className="mt-1 text-sm text-gray-900 dark:text-gray-100">
                {expense.metadata?.payment_date
                  ? format(new Date(expense.metadata.payment_date), 'PPP')
                  : '-'}
              </p>
            </div>
          </div>
          {expense.metadata?.is_paid && (
            <div className="mt-4">
              <span className="inline-flex items-center rounded-full bg-green-100 px-3 py-1 text-sm font-medium text-green-800 dark:bg-green-900/20 dark:text-green-400">
                {t('expenses:paid')}
              </span>
            </div>
          )}
        </div>

        {/* Notes */}
        {(expense.notes || expense.internal_notes) && (
          <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <h2 className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
              {t('expenses:notes')}
            </h2>
            {expense.notes && (
              <div className="mb-4">
                <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                  {t('expenses:form.notes')}
                </label>
                <p className="mt-1 text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap">
                  {expense.notes}
                </p>
              </div>
            )}
            {expense.internal_notes && (
              <div>
                <label className="text-sm font-medium text-gray-500 dark:text-gray-400">
                  {t('expenses:form.internalNotes')}
                </label>
                <p className="mt-1 text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap">
                  {expense.internal_notes}
                </p>
              </div>
            )}
          </div>
        )}

        {/* Attachments */}
        <div className="rounded-lg border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
          <h2 className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
            {t('expenses:attachments')}
          </h2>
          <DocumentAttachments documentId={expense.id} readOnly={isPosted} />
        </div>
      </div>
    </div>
  )
}
