import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ExpenseCategorySelect } from '../molecules/ExpenseCategorySelect'
import { useActivePaymentMethods } from '../../../treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '../../../treasury/hooks/usePaymentRepositories'
import { useCurrency } from '../../../../hooks/useCurrency'
import {
  Button,
  FormField,
  Input,
  MoneyInput,
  Select,
  Textarea,
} from '../../../../components/atoms'
import { StickyFormFooter } from '../../../../components/molecules/StickyFormFooter/StickyFormFooter'
import { tokens, textColors } from '../../../../lib/designTokens'
import { bccomp } from '../../../../lib/decimal'
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
  const { currency } = useCurrency()

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
  const totalValue = watch('total')

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      {/* Vendor Information */}
      <div className="space-y-4">
        <h2 className={tokens.heading.section}>{t('expenses:form.vendorInfo')}</h2>

        <FormField label={t('expenses:form.vendorName')} htmlFor="vendor_name">
          <Input
            id="vendor_name"
            type="text"
            {...register('vendor_name')}
            placeholder={t('expenses:form.vendorNamePlaceholder')}
          />
        </FormField>

        <FormField label={t('expenses:form.receiptNumber')} htmlFor="receipt_number">
          <Input
            id="receipt_number"
            type="text"
            {...register('receipt_number')}
            placeholder={t('expenses:form.receiptNumberPlaceholder')}
          />
        </FormField>
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
        <FormField
          label={t('expenses:form.amount')}
          htmlFor="total"
          required
          error={errors.total?.message}
        >
          <MoneyInput
            id="total"
            {...register('total', {
              required: t('common:validation.required'),
              validate: (v) =>
                bccomp(v ?? '0', '0.01') >= 0 ||
                t('common:validation.minAmount', { amount: '0.01' }),
            })}
            currency={currency}
            value={totalValue}
            onChange={(v) => {
              setValue('total', v)
            }}
            min="0.01"
            error={!!errors.total}
          />
        </FormField>

        <FormField
          label={t('expenses:form.date')}
          htmlFor="document_date"
          required
          error={errors.document_date?.message}
        >
          <Input
            id="document_date"
            type="date"
            {...register('document_date', {
              required: t('common:validation.required'),
            })}
            error={!!errors.document_date}
          />
        </FormField>
      </div>

      {/* Payment Details */}
      <div className="space-y-4">
        <h2 className={tokens.heading.section}>
          {t('expenses:form.paymentDetails')}
        </h2>

        <FormField label={t('expenses:form.paymentDate')} htmlFor="payment_date">
          <Input id="payment_date" type="date" {...register('payment_date')} />
        </FormField>

        {/* Payment Method */}
        <FormField
          label={t('expenses:form.paymentMethod')}
          htmlFor="payment_method_id"
          {...(methodsError ? { error: t('common:error') } : {})}
          {...(!isLoadingMethods && !methodsError && paymentMethods.length === 0
            ? { helperText: t('expenses:form.noPaymentMethodsDescription') }
            : {})}
        >
          <Select
            id="payment_method_id"
            {...register('payment_method_id')}
            disabled={isLoadingMethods || paymentMethods.length === 0}
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
          </Select>
        </FormField>

        {/* Payment Repository */}
        <FormField
          label={t('expenses:form.paymentRepository')}
          htmlFor="payment_repository_id"
          {...(repositoriesError ? { error: t('common:error') } : {})}
          {...(!isLoadingRepositories &&
          !repositoriesError &&
          paymentRepositories.length === 0
            ? { helperText: t('expenses:form.noRepositoriesDescription') }
            : {})}
        >
          <Select
            id="payment_repository_id"
            {...register('payment_repository_id')}
            disabled={isLoadingRepositories || paymentRepositories.length === 0}
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
          </Select>
        </FormField>

        <div className="flex items-center">
          <input
            type="checkbox"
            {...register('is_paid')}
            id="is_paid"
            className={tokens.checkbox.base}
          />
          <label htmlFor="is_paid" className={`ms-2 text-sm ${textColors.secondary}`}>
            {t('expenses:form.isPaid')}
          </label>
        </div>
      </div>

      {/* Notes */}
      <div className="space-y-4">
        <FormField label={t('expenses:form.notes')} htmlFor="notes">
          <Textarea
            id="notes"
            {...register('notes')}
            rows={3}
            placeholder={t('expenses:form.notesPlaceholder')}
          />
        </FormField>

        <FormField label={t('expenses:form.internalNotes')} htmlFor="internal_notes">
          <Textarea
            id="internal_notes"
            {...register('internal_notes')}
            rows={2}
            placeholder={t('expenses:form.internalNotesPlaceholder')}
          />
        </FormField>
      </div>

      {/* Actions */}
      <StickyFormFooter>
        {onCancel && (
          <Button
            type="button"
            variant="secondary"
            onClick={onCancel}
            disabled={isSubmitting}
          >
            {t('common:cancel')}
          </Button>
        )}
        <Button type="submit" variant="primary" disabled={isSubmitting}>
          {isSubmitting ? t('common:saving') : t('common:save')}
        </Button>
      </StickyFormFooter>
    </form>
  )
}
