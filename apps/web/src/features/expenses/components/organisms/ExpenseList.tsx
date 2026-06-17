import { useTranslation } from 'react-i18next'
import { Inbox } from 'lucide-react'
import { ExpenseCard } from './ExpenseCard'
import type { Expense } from '../../types'
import { cn } from '@/lib/utils'
import { colors, textColors, borderColors } from '@/lib/designTokens'

interface ExpenseListProps {
  expenses: Expense[]
  isLoading?: boolean
  onDelete?: (id: string) => void
  onPost?: (id: string) => void
}

/**
 * Organism: Expense list
 *
 * Displays a grid of expense cards with loading and empty states.
 */
export function ExpenseList({ expenses, isLoading, onDelete, onPost }: ExpenseListProps) {
  const { t } = useTranslation(['expenses', 'common'])

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 6 }).map((_, i) => (
          <div
            key={i}
            className={cn('h-64 animate-pulse rounded-lg', colors.neutral[200])}
          />
        ))}
      </div>
    )
  }

  if (expenses.length === 0) {
    return (
      <div
        className={cn(
          'flex flex-col items-center justify-center rounded-lg border-2 border-dashed py-12',
          borderColors.default,
          colors.neutral[50]
        )}
      >
        <Inbox className={cn('h-12 w-12', textColors.disabled)} />
        <h3 className={cn('mt-4 text-lg font-medium', textColors.primary)}>
          {t('expenses:noExpenses')}
        </h3>
        <p className={cn('mt-2 text-sm', textColors.disabled)}>
          {t('expenses:noExpensesDescription')}
        </p>
      </div>
    )
  }

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
      {expenses.map((expense) => (
        <ExpenseCard
          key={expense.id}
          expense={expense}
          {...(onDelete ? { onDelete } : {})}
          {...(onPost ? { onPost } : {})}
        />
      ))}
    </div>
  )
}
