import { useRef } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { useExpense, useCreateExpense, useUpdateExpense } from '../hooks/useExpenses'
import { ExpenseFormFields } from '../components/organisms/ExpenseFormFields'
import { PageHeader } from '../../../components/molecules/PageHeader'
import { Button } from '../../../components/atoms'
import { tokens, textColors, colors } from '../../../lib/designTokens'
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

  // Stable idempotency key for the current create session — generated once on
  // mount and reused across retries so the backend can deduplicate.
  const idempotencyKeyRef = useRef<string>(crypto.randomUUID())

  const handleSubmit = async (data: CreateExpenseDTO) => {
    try {
      if (isEditMode && id) {
        await updateExpense.mutateAsync({ id, data })
        navigate(`/expenses/${id}/view`)
      } else {
        const createdExpense = await createExpense.mutateAsync({
          ...data,
          idempotency_key: idempotencyKeyRef.current,
        })
        // Redirect to detail page after creation so user can add attachments
        navigate(`/expenses/${createdExpense.id}/view`)
      }
    } catch {
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
          <div className={`h-8 w-48 rounded ${colors.neutral[200]}`} />
          <div className={`h-96 rounded-lg ${colors.neutral[200]}`} />
        </div>
      </div>
    )
  }

  if (isEditMode && !expense) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
        <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
          {t('expenses:errors.notFound')}
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-3xl px-4 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <Button
        variant="ghost"
        size="sm"
        onClick={() => navigate('/expenses')}
        className={`mb-4 gap-2 ${textColors.tertiary} ${textColors.hoverPrimary}`}
      >
        <ArrowLeft className="h-4 w-4" />
        {t('common:back')}
      </Button>
      <PageHeader
        title={isEditMode ? t('expenses:editExpense') : t('expenses:createExpense')}
        {...(isEditMode && expense ? { subtitle: expense.document_number } : {})}
      />

      {/* Form */}
      <div className={tokens.card.base}>
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
