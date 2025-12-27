import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Calendar, FileText, Tag, TrendingUp, Trash2 } from 'lucide-react'
import type { Expense } from '../../types'

interface ExpenseCardProps {
  expense: Expense
  onDelete?: (id: string) => void
  onPost?: (id: string) => void
}

/**
 * Organism: Expense card
 *
 * Displays an expense in a card format with key information,
 * status badge, and action buttons.
 */
export function ExpenseCard({ expense, onDelete, onPost }: ExpenseCardProps) {
  const { t } = useTranslation(['expenses', 'common'])

  const statusColors = {
    draft: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    confirmed: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
    posted: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
  }

  const isDraft = expense.status === 'draft'
  const isPosted = expense.status === 'posted'

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
      {/* Header */}
      <div className="mb-3 flex items-start justify-between">
        <div className="flex-1">
          <Link
            to={`/expenses/${expense.id}/view`}
            className="text-lg font-semibold text-gray-900 hover:text-primary-600 dark:text-gray-100 dark:hover:text-primary-400"
          >
            {expense.document_number}
          </Link>
          {expense.metadata?.vendor_name && (
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">
              {expense.metadata.vendor_name}
            </p>
          )}
        </div>
        <span
          className={`rounded-full px-3 py-1 text-xs font-medium ${
            statusColors[expense.status as keyof typeof statusColors]
          }`}
        >
          {t(`expenses:status.${expense.status}`)}
        </span>
      </div>

      {/* Amount */}
      <div className="mb-3">
        <div className="flex items-baseline gap-1">
          <span className="text-2xl font-bold text-gray-900 dark:text-gray-100">
            {parseFloat(expense.total).toFixed(2)}
          </span>
          <span className="text-sm text-gray-500 dark:text-gray-400">
            {expense.currency}
          </span>
        </div>
      </div>

      {/* Metadata */}
      <div className="space-y-2 border-t border-gray-100 pt-3 dark:border-gray-700">
        <div className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
          <Calendar className="h-4 w-4" />
          <span>
            {format(new Date(expense.document_date), 'PPP')}
          </span>
        </div>

        {expense.metadata?.category && (
          <div className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <Tag className="h-4 w-4" />
            <span>{expense.metadata.category.name}</span>
          </div>
        )}

        {expense.metadata?.receipt_number && (
          <div className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
            <FileText className="h-4 w-4" />
            <span>{expense.metadata.receipt_number}</span>
          </div>
        )}

        {expense.metadata?.is_paid && (
          <div className="flex items-center gap-2 text-sm text-green-600 dark:text-green-400">
            <TrendingUp className="h-4 w-4" />
            <span>{t('expenses:paid')}</span>
          </div>
        )}
      </div>

      {/* Actions */}
      {(isDraft || !isPosted) && (onDelete || onPost) && (
        <div className="mt-4 flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-gray-700">
          {isDraft && onPost && (
            <button
              onClick={() => onPost(expense.id)}
              className="flex-1 rounded-md bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
            >
              {t('expenses:actions.post')}
            </button>
          )}
          {isDraft && onDelete && (
            <button
              onClick={() => onDelete(expense.id)}
              className="rounded-md border border-red-300 px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          )}
        </div>
      )}
    </div>
  )
}
