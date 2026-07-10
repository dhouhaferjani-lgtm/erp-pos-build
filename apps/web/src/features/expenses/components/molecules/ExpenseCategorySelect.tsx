import { useTranslation } from 'react-i18next'
import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import type { ExpenseCategory } from '../../types'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface ExpenseCategorySelectProps {
  value: string
  onChange: (value: string) => void
  disabled?: boolean
  showInactive?: boolean
  placeholder?: string
}

/**
 * Molecule: Expense category select dropdown
 *
 * A reusable select component for choosing expense categories.
 * Supports hierarchical display with parent-child relationships.
 */
export function ExpenseCategorySelect({
  value,
  onChange,
  disabled = false,
  showInactive = false,
  placeholder,
}: ExpenseCategorySelectProps) {
  const { t } = useTranslation(['expenses', 'common'])
  const { data: categories, isLoading, error } = useExpenseCategories(
    showInactive ? {} : { is_active: true }
  )

  const hasCategories = categories && categories.length > 0

  /**
   * Build hierarchical category options
   */
  const buildCategoryOptions = (cats: ExpenseCategory[] = []): React.ReactElement[] => {
    const rootCategories = cats.filter((cat) => !cat.parent_id)
    const childCategories = cats.filter((cat) => cat.parent_id)

    const options: React.ReactElement[] = []

    rootCategories.forEach((root) => {
      options.push(
        <option key={root.id} value={root.id}>
          {root.name}
        </option>
      )

      const children = childCategories.filter((child) => child.parent_id === root.id)
      children.forEach((child) => {
        options.push(
          <option key={child.id} value={child.id}>
            &nbsp;&nbsp;↳ {child.name}
          </option>
        )
      })
    })

    return options
  }

  return (
    <div className="space-y-2">
      <label className={`text-sm font-medium ${colorTokens.text.secondary} dark:${colorTokens.text.faint}`}>
        {t('expenses:form.category')}
      </label>
      <select
        value={value || ''}
        onChange={(e) => { onChange(e.target.value); }}
        disabled={disabled || isLoading || !hasCategories}
        className={`w-full rounded-md border ${colorTokens.border.default} bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:cursor-not-allowed disabled:${colorTokens.surface.page} disabled:${colorTokens.text.subtle} dark:${colorTokens.border.inverse} dark:${colorTokens.surface.inverse} dark:${colorTokens.text.inverseFaint} dark:disabled:${colorTokens.surface.inverseStrong}`}
      >
        <option value="">
          {isLoading
            ? t('common:loading')
            : !hasCategories
            ? t('expenses:categories.noCategories')
            : placeholder || t('expenses:form.selectCategory')}
        </option>
        {buildCategoryOptions(categories)}
      </select>
      {error && (
        <p className={`text-sm ${colorTokens.intent.danger.text} dark:${colorTokens.intent.danger.textFaint}`}>
          {t('common:error')}
        </p>
      )}
      {!isLoading && !error && !hasCategories && (
        <p className={`text-sm ${colorTokens.text.subtle} dark:${colorTokens.text.disabled}`}>
          {t('expenses:categories.noCategoriesDescription')}{' '}
          <a
            href="/expenses/categories"
            className="text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
          >
            {t('expenses:categories.create')}
          </a>
        </p>
      )}
    </div>
  )
}
