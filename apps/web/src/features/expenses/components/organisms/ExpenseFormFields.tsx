import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ExpenseCategorySelect } from '../molecules/ExpenseCategorySelect'
import { useActivePaymentMethods } from '../../../treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '../../../treasury/hooks/usePaymentRepositories'
import { useLinkableExpenseInvoices, useLinkableExpenseOperations } from '../../hooks/useExpenses'
import { useCurrency } from '../../../../hooks/useCurrency'
import {
  Button,
  Checkbox,
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
          expense_kind: expense.metadata?.expense_kind || 'generic',
          cost_type: expense.metadata?.cost_type || 'transport',
          split_method: expense.metadata?.split_method || 'by_value',
          document_date: expense.document_date,
        }
      : {
          document_date: new Date().toISOString().split('T')[0],
          is_paid: false,
          expense_kind: 'generic',
          cost_type: 'transport',
          split_method: 'by_value',
          total: '',
        },
  })

  const categoryId = watch('expense_category_id')
  const totalValue = watch('total')
  const expenseKind = watch('expense_kind') || 'generic'
  const linkedOperationId = watch('linked_operation_id')
  const [selectedLinkedInvoiceId, setSelectedLinkedInvoiceId] = useState(
    expense?.metadata?.linked_invoice_id ?? ''
  )
  const isLinkedCost = expenseKind === 'linked_cost'
  const { data: linkableInvoices = [], isLoading: isLoadingInvoices } =
    useLinkableExpenseInvoices(isLinkedCost)
  const { data: operationResolution } = useLinkableExpenseOperations(
    isLinkedCost ? selectedLinkedInvoiceId : undefined
  )

  useEffect(() => {
    if (!operationResolution?.auto_selected_id) {
      return
    }
    setValue('linked_operation_id', operationResolution.auto_selected_id)
  }, [operationResolution?.auto_selected_id, setValue])

  const selectedOperationId = linkedOperationId || operationResolution?.auto_selected_id
  const selectedOperation = operationResolution?.operations.find(
    (operation) => operation.document_id === selectedOperationId
  )
  const linkedInvoiceField = register('linked_invoice_id')

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

      <div className="space-y-4">
        <h2 className={tokens.heading.section}>{t('expenses:form.classification')}</h2>

        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <label className="flex min-h-12 items-center gap-3 rounded-md border border-gray-200 px-3 py-2 text-sm">
            <input
              type="radio"
              value="generic"
              {...register('expense_kind')}
              className="h-4 w-4"
            />
            <span>{t('expenses:form.kindGeneric')}</span>
          </label>
          <label className="flex min-h-12 items-center gap-3 rounded-md border border-gray-200 px-3 py-2 text-sm">
            <input
              type="radio"
              value="linked_cost"
              {...register('expense_kind')}
              className="h-4 w-4"
            />
            <span>{t('expenses:form.kindLinked')}</span>
          </label>
        </div>

        {isLinkedCost && (
          <div className="space-y-4 rounded-md border border-gray-200 p-4">
            <FormField label={t('expenses:form.linkedInvoice')} htmlFor="linked_invoice_id">
              <Select
                id="linked_invoice_id"
                {...linkedInvoiceField}
                onChange={(event) => {
                  void linkedInvoiceField.onChange(event)
                  setValue('linked_invoice_id', event.target.value)
                  setSelectedLinkedInvoiceId(event.target.value)
                }}
                disabled={isLoadingInvoices}
              >
                <option value="">
                  {isLoadingInvoices ? t('common:loading') : t('common:select')}
                </option>
                {linkableInvoices.map((invoice) => (
                  <option key={invoice.id} value={invoice.id}>
                    {invoice.document_number}
                    {invoice.partner_name ? ` · ${invoice.partner_name}` : ''}
                  </option>
                ))}
              </Select>
            </FormField>

            {operationResolution && operationResolution.operations.length === 0 && (
              <div className={tokens.alert.base}>
                <button
                  type="button"
                  className="text-sm font-medium text-blue-700"
                  onClick={() => {
                    setValue('expense_kind', 'generic')
                    setValue('linked_invoice_id', undefined)
                    setValue('linked_operation_id', undefined)
                    setSelectedLinkedInvoiceId('')
                  }}
                >
                  {t('expenses:form.downgradeToGeneric')}
                </button>
              </div>
            )}

            {selectedOperation && (
              <div className="flex flex-wrap items-center gap-2 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700">
                <span className="font-medium">{t('expenses:form.linkedTo')}</span>
                <span>{selectedOperation.number}</span>
                <span>{selectedOperation.status}</span>
              </div>
            )}

            {operationResolution && operationResolution.operations.length > 1 && (
              <FormField label={t('expenses:form.linkedOperation')} htmlFor="linked_operation_id">
                <Select id="linked_operation_id" {...register('linked_operation_id')}>
                  <option value="">{t('common:select')}</option>
                  {operationResolution.operations.map((operation) => (
                    <option key={operation.document_id} value={operation.document_id}>
                      {operation.number}
                    </option>
                  ))}
                </Select>
              </FormField>
            )}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <FormField label={t('expenses:form.costType')} htmlFor="cost_type">
                <Select id="cost_type" {...register('cost_type')} defaultValue="transport">
                  <option value="transport">{t('expenses:costTypes.transport')}</option>
                  <option value="shipping">{t('expenses:costTypes.shipping')}</option>
                  <option value="insurance">{t('expenses:costTypes.insurance')}</option>
                  <option value="customs">{t('expenses:costTypes.customs')}</option>
                  <option value="handling">{t('expenses:costTypes.handling')}</option>
                  <option value="other">{t('expenses:costTypes.other')}</option>
                </Select>
              </FormField>

              <FormField label={t('expenses:form.splitMethod')} htmlFor="split_method">
                <Select id="split_method" {...register('split_method')} defaultValue="by_value">
                  <option value="by_value">{t('expenses:splitMethods.byValue')}</option>
                  <option value="by_quantity">{t('expenses:splitMethods.byQuantity')}</option>
                </Select>
              </FormField>
            </div>
          </div>
        )}
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
          <Checkbox
            {...register('is_paid')}
            id="is_paid"
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
