import { useTranslation } from 'react-i18next'
import { Inbox } from 'lucide-react'
import { ExpenseCard } from './ExpenseCard'
import type { Expense } from '../../types'

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
            className="h-64 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"
          />
        ))}
      </div>
    )
  }

  if (expenses.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 py-12 dark:border-gray-700 dark:bg-gray-800">
        <Inbox className="h-12 w-12 text-gray-400" />
        <h3 className="mt-4 text-lg font-medium text-gray-900 dark:text-gray-100">
          {t('expenses:noExpenses')}
        </h3>
        <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
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
