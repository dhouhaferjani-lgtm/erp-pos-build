import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Calendar, FileText, Tag, TrendingUp, Trash2 } from 'lucide-react'
import type { Expense } from '../../types'
import { useCurrency } from '@/hooks/useCurrency'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { Button } from '@/components/atoms/Button'
import {
  StatusBadge,
  statusTone,
  type StatusTone,
} from '@/components/atoms/StatusBadge'

interface ExpenseCardProps {
  expense: Expense
  onDelete?: (id: string) => void
  onPost?: (id: string) => void
}

/**
 * Document-status tone overrides for the shared StatusBadge. `confirmed` and
 * `posted` are not covered by the built-in statusTone map, so they are mapped
 * here (info / success) to preserve the original blue / green treatment.
 */
const statusToneOverrides: Record<string, StatusTone> = {
  confirmed: 'info',
  posted: 'success',
}

/**
 * Organism: Expense card
 *
 * Displays an expense in a card format with key information,
 * status badge, and action buttons.
 */
export function ExpenseCard({ expense, onDelete, onPost }: ExpenseCardProps) {
  const { t } = useTranslation(['expenses', 'common'])
  const { decimals } = useCurrency()

  const isDraft = expense.status === 'draft'
  const isPosted = expense.status === 'posted'

  return (
    <div className={cn(tokens.card.base, 'p-4', tokens.card.hover)}>
      {/* Header */}
      <div className="mb-3 flex items-start justify-between">
        <div className="flex-1">
          <Link
            to={`/expenses/${expense.id}/view`}
            className={cn(
              'text-lg font-semibold',
              textColors.primary,
              'hover:text-primary-600 dark:hover:text-primary-400'
            )}
          >
            {expense.document_number}
          </Link>
          {expense.metadata?.vendor_name && (
            <p className={cn('mt-1 text-sm', textColors.tertiary)}>
              {expense.metadata.vendor_name}
            </p>
          )}
        </div>
        <StatusBadge tone={statusTone(expense.status, statusToneOverrides)}>
          {t(`expenses:status.${expense.status}`)}
        </StatusBadge>
      </div>

      {/* Amount */}
      <div className="mb-3">
        <div className="flex items-baseline gap-1">
          <span
            className={cn('text-2xl font-bold tabular-nums', textColors.primary)}
          >
            {parseFloat(expense.total).toFixed(decimals)}
          </span>
          <span className={cn('text-sm', textColors.disabled)}>
            {expense.currency}
          </span>
        </div>
      </div>

      {/* Metadata */}
      <div className={cn('space-y-2 border-t pt-3', borderColors.light)}>
        <div className={cn('flex items-center gap-2 text-sm', textColors.tertiary)}>
          <Calendar className="h-4 w-4" />
          <span>{format(new Date(expense.document_date), 'PPP')}</span>
        </div>

        {expense.metadata?.category && (
          <div
            className={cn('flex items-center gap-2 text-sm', textColors.tertiary)}
          >
            <Tag className="h-4 w-4" />
            <span>{expense.metadata.category.name}</span>
          </div>
        )}

        {expense.metadata?.receipt_number && (
          <div
            className={cn('flex items-center gap-2 text-sm', textColors.tertiary)}
          >
            <FileText className="h-4 w-4" />
            <span>{expense.metadata.receipt_number}</span>
          </div>
        )}

        {expense.metadata?.is_paid && (
          <div
            className={cn('flex items-center gap-2 text-sm', textColors.success)}
          >
            <TrendingUp className="h-4 w-4" />
            <span>{t('expenses:paid')}</span>
          </div>
        )}
      </div>

      {/* Actions */}
      {(isDraft || !isPosted) && (onDelete || onPost) && (
        <div
          className={cn(
            'mt-4 flex items-center gap-2 border-t pt-3',
            borderColors.light
          )}
        >
          {isDraft && onPost && (
            <Button
              variant="primary"
              size="md"
              className="flex-1"
              onClick={() => {
                onPost(expense.id)
              }}
            >
              {t('expenses:actions.post')}
            </Button>
          )}
          {isDraft && onDelete && (
            <Button
              variant="danger"
              size="md"
              onClick={() => {
                onDelete(expense.id)
              }}
              aria-label={t('common:delete')}
            >
              <Trash2 className="h-4 w-4" />
            </Button>
          )}
        </div>
      )}
    </div>
  )
}
