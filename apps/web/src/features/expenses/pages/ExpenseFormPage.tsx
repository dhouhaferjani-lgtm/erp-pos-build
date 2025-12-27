import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { useExpense, useCreateExpense, useUpdateExpense } from '../hooks/useExpenses'
import { ExpenseFormFields } from '../components/organisms/ExpenseFormFields'
import type { CreateExpenseDTO } from '../types'

/**
 * Page: Expense form
 *
 * Page for creating a new expense or editing an existing one.
 */
export function ExpenseFormPage() {
  const { t } = useTranslation(['expenses', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditMode = !!id

  const { data: expense, isLoading } = useExpense(id || '')
  const createExpense = useCreateExpense()
  const updateExpense = useUpdateExpense()

  const handleSubmit = async (data: CreateExpenseDTO) => {
    try {
      if (isEditMode && id) {
        await updateExpense.mutateAsync({ id, data })
        navigate(`/expenses/${id}/view`)
      } else {
        const createdExpense = await createExpense.mutateAsync(data)
        // Redirect to detail page after creation so user can add attachments
        navigate(`/expenses/${createdExpense.id}/view`)
      }
    } catch (error) {
      // Error handling is done in the hooks
    }
  }

  const handleCancel = () => {
    navigate('/expenses')
  }

  if (isEditMode && isLoading) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="animate-pulse space-y-4">
          <div className="h-8 w-48 rounded bg-gray-200 dark:bg-gray-700" />
          <div className="h-96 rounded-lg bg-gray-200 dark:bg-gray-700" />
        </div>
      </div>
    )
  }

  if (isEditMode && !expense) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div className="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
          <p className="text-sm text-red-800 dark:text-red-200">
            {t('expenses:errors.notFound')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="mb-6">
        <button
          onClick={() => navigate('/expenses')}
          className="mb-4 inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:back')}
        </button>
        <h1 className="text-3xl font-bold text-gray-900 dark:text-gray-100">
          {isEditMode ? t('expenses:editExpense') : t('expenses:createExpense')}
        </h1>
        {isEditMode && expense && (
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            {expense.document_number}
          </p>
        )}
      </div>

      {/* Form */}
      <div className="rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <ExpenseFormFields
          {...(expense ? { expense } : {})}
          onSubmit={handleSubmit}
          onCancel={handleCancel}
          isSubmitting={createExpense.isPending || updateExpense.isPending}
        />
      </div>
    </div>
  )
}
