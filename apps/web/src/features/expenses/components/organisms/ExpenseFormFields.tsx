import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ExpenseCategorySelect } from '../molecules/ExpenseCategorySelect'
import { useActivePaymentMethods } from '../../../treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '../../../treasury/hooks/usePaymentRepositories'
import type { CreateExpenseDTO, Expense } from '../../types'

interface ExpenseFormFieldsProps {
  expense?: Expense
  onSubmit: (data: CreateExpenseDTO) => void
  onCancel?: () => void
  isSubmitting?: boolean
}

/**
 * Organism: Expense form fields
 *
 * Comprehensive form for creating or editing expenses.
 * Includes vendor info, category, payment details, amount, and notes.
 */
export function ExpenseFormFields({
  expense,
  onSubmit,
  onCancel,
  isSubmitting = false,
}: ExpenseFormFieldsProps) {
  const { t } = useTranslation(['expenses', 'common'])

  // Fetch payment methods and repositories
  const {
    data: paymentMethods = [],
    isLoading: isLoadingMethods,
    error: methodsError,
  } = useActivePaymentMethods()
  const {
    data: paymentRepositories = [],
    isLoading: isLoadingRepositories,
    error: repositoriesError,
  } = useActivePaymentRepositories()

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<CreateExpenseDTO>({
    defaultValues: expense
      ? {
          vendor_name: expense.metadata?.vendor_name || '',
          expense_category_id: expense.metadata?.expense_category_id || '',
          payment_method_id: expense.metadata?.payment_method_id || '',
          payment_repository_id: expense.metadata?.payment_repository_id || '',
          payment_date: expense.metadata?.payment_date || '',
          receipt_number: expense.metadata?.receipt_number || '',
          total: expense.total,
          notes: expense.notes || '',
          internal_notes: expense.internal_notes || '',
          is_paid: expense.metadata?.is_paid || false,
          document_date: expense.document_date,
        }
      : {
          document_date: new Date().toISOString().split('T')[0],
          is_paid: false,
        },
  })

  const categoryId = watch('expense_category_id')

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      {/* Vendor Information */}
      <div className="space-y-4">
        <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
          {t('expenses:form.vendorInfo')}
        </h3>

        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.vendorName')}
          </label>
          <input
            type="text"
            {...register('vendor_name')}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            placeholder={t('expenses:form.vendorNamePlaceholder')}
          />
        </div>

        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.receiptNumber')}
          </label>
          <input
            type="text"
            {...register('receipt_number')}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            placeholder={t('expenses:form.receiptNumberPlaceholder')}
          />
        </div>
      </div>

      {/* Category */}
      <div>
        <ExpenseCategorySelect
          value={categoryId || ''}
          onChange={(value) => {
            setValue('expense_category_id', value || undefined)
          }}
        />
      </div>

      {/* Amount and Date */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.amount')} *
          </label>
          <input
            type="number"
            step="0.01"
            {...register('total', {
              required: t('common:validation.required'),
              min: {
                value: 0.01,
                message: t('common:validation.minAmount', { amount: '0.01' }),
              },
            })}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
          />
          {errors.total && (
            <p className="mt-1 text-sm text-red-600">{errors.total.message}</p>
          )}
        </div>

        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.date')} *
          </label>
          <input
            type="date"
            {...register('document_date', {
              required: t('common:validation.required'),
            })}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
          />
          {errors.document_date && (
            <p className="mt-1 text-sm text-red-600">{errors.document_date.message}</p>
          )}
        </div>
      </div>

      {/* Payment Details */}
      <div className="space-y-4">
        <h3 className="text-lg font-medium text-gray-900 dark:text-gray-100">
          {t('expenses:form.paymentDetails')}
        </h3>

        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.paymentDate')}
          </label>
          <input
            type="date"
            {...register('payment_date')}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
          />
        </div>

        {/* Payment Method */}
        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.paymentMethod')}
          </label>
          <select
            {...register('payment_method_id')}
            disabled={isLoadingMethods || paymentMethods.length === 0}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900"
          >
            <option value="">
              {isLoadingMethods
                ? t('common:loading')
                : paymentMethods.length === 0
                ? t('expenses:form.noPaymentMethods')
                : t('common:select')}
            </option>
            {paymentMethods.map((method) => (
              <option key={method.id} value={method.id}>
                {method.name}
              </option>
            ))}
          </select>
          {methodsError && (
            <p className="mt-1 text-sm text-red-600 dark:text-red-400">
              {t('common:error')}
            </p>
          )}
          {!isLoadingMethods && !methodsError && paymentMethods.length === 0 && (
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              {t('expenses:form.noPaymentMethodsDescription')}
            </p>
          )}
        </div>

        {/* Payment Repository */}
        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.paymentRepository')}
          </label>
          <select
            {...register('payment_repository_id')}
            disabled={isLoadingRepositories || paymentRepositories.length === 0}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-900"
          >
            <option value="">
              {isLoadingRepositories
                ? t('common:loading')
                : paymentRepositories.length === 0
                ? t('expenses:form.noRepositories')
                : t('common:select')}
            </option>
            {paymentRepositories.map((repo) => (
              <option key={repo.id} value={repo.id}>
                {repo.name} ({repo.code})
              </option>
            ))}
          </select>
          {repositoriesError && (
            <p className="mt-1 text-sm text-red-600 dark:text-red-400">
              {t('common:error')}
            </p>
          )}
          {!isLoadingRepositories && !repositoriesError && paymentRepositories.length === 0 && (
            <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
              {t('expenses:form.noRepositoriesDescription')}
            </p>
          )}
        </div>

        <div className="flex items-center">
          <input
            type="checkbox"
            {...register('is_paid')}
            id="is_paid"
            className="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
          />
          <label htmlFor="is_paid" className="ms-2 text-sm text-gray-700 dark:text-gray-300">
            {t('expenses:form.isPaid')}
          </label>
        </div>
      </div>

      {/* Notes */}
      <div className="space-y-4">
        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.notes')}
          </label>
          <textarea
            {...register('notes')}
            rows={3}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            placeholder={t('expenses:form.notesPlaceholder')}
          />
        </div>

        <div>
          <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
            {t('expenses:form.internalNotes')}
          </label>
          <textarea
            {...register('internal_notes')}
            rows={2}
            className="mt-1 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            placeholder={t('expenses:form.internalNotesPlaceholder')}
          />
        </div>
      </div>

      {/* Actions */}
      <div className="flex items-center justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
        {onCancel && (
          <button
            type="button"
            onClick={onCancel}
            disabled={isSubmitting}
            className="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
          >
            {t('common:cancel')}
          </button>
        )}
        <button
          type="submit"
          disabled={isSubmitting}
          className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
        >
          {isSubmitting ? t('common:saving') : t('common:save')}
        </button>
      </div>
    </form>
  )
}
