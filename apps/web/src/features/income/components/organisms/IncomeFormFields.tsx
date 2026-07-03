import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { useActivePaymentMethods } from '../../../treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '../../../treasury/hooks/usePaymentRepositories'
import { useAccounts } from '../../../finance/hooks/useAccounts'
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
import type { CreateIncomeDTO, Income } from '../../types'

interface IncomeFormFieldsProps {
  income?: Income
  onSubmit: (data: CreateIncomeDTO) => void
  onCancel?: () => void
  isSubmitting?: boolean
}

/**
 * Organism: Income form fields — the mirror of ExpenseFormFields.
 *
 * The "category" is a class-7 revenue GL account, fetched via the finance
 * accounts endpoint filtered to `type=revenue`.
 */
export function IncomeFormFields({
  income,
  onSubmit,
  onCancel,
  isSubmitting = false,
}: IncomeFormFieldsProps) {
  const { t } = useTranslation(['income', 'common'])
  const { currency } = useCurrency()

  const {
    data: incomeAccounts = [],
    isLoading: isLoadingAccounts,
  } = useAccounts({ type: 'revenue', active: true })
  const {
    data: paymentMethods = [],
    isLoading: isLoadingMethods,
  } = useActivePaymentMethods()
  const {
    data: paymentRepositories = [],
    isLoading: isLoadingRepositories,
  } = useActivePaymentRepositories()

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<CreateIncomeDTO>({
    defaultValues: income
      ? {
          source_name: income.metadata?.source_name || '',
          income_account_id: income.metadata?.income_account_id || '',
          payment_method_id: income.metadata?.payment_method_id || '',
          payment_repository_id: income.metadata?.payment_repository_id || '',
          payment_date: income.metadata?.payment_date || '',
          reference_number: income.metadata?.reference_number || '',
          total: income.total,
          notes: income.notes || '',
          is_received: income.metadata?.is_received ?? true,
          document_date: income.document_date,
        }
      : {
          document_date: new Date().toISOString().split('T')[0],
          is_received: true,
          total: '',
        },
  })

  const totalValue = watch('total')

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      {/* Source Information */}
      <div className="space-y-4">
        <h2 className={tokens.heading.section}>{t('income:form.sourceInfo')}</h2>

        <FormField label={t('income:form.sourceName')} htmlFor="source_name">
          <Input
            id="source_name"
            type="text"
            {...register('source_name')}
            placeholder={t('income:form.sourceNamePlaceholder')}
          />
        </FormField>

        <FormField label={t('income:form.referenceNumber')} htmlFor="reference_number">
          <Input
            id="reference_number"
            type="text"
            {...register('reference_number')}
            placeholder={t('income:form.referenceNumberPlaceholder')}
          />
        </FormField>
      </div>

      {/* Income account (class-7 revenue) */}
      <FormField
        label={t('income:form.incomeAccount')}
        htmlFor="income_account_id"
        {...(!isLoadingAccounts && incomeAccounts.length === 0
          ? { helperText: t('income:form.noIncomeAccountsDescription') }
          : {})}
      >
        <Select
          id="income_account_id"
          {...register('income_account_id')}
          disabled={isLoadingAccounts || incomeAccounts.length === 0}
        >
          <option value="">
            {isLoadingAccounts
              ? t('common:loading')
              : incomeAccounts.length === 0
                ? t('income:form.noIncomeAccounts')
                : t('income:form.selectIncomeAccount')}
          </option>
          {incomeAccounts.map((account) => (
            <option key={account.id} value={account.id}>
              {account.code} · {account.name}
            </option>
          ))}
        </Select>
      </FormField>

      {/* Amount and Date */}
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <FormField
          label={t('income:form.amount')}
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
          label={t('income:form.date')}
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

      {/* Receipt Details */}
      <div className="space-y-4">
        <h2 className={tokens.heading.section}>{t('income:form.paymentDetails')}</h2>

        <FormField label={t('income:form.paymentDate')} htmlFor="payment_date">
          <Input id="payment_date" type="date" {...register('payment_date')} />
        </FormField>

        <FormField label={t('income:form.paymentMethod')} htmlFor="payment_method_id">
          <Select
            id="payment_method_id"
            {...register('payment_method_id')}
            disabled={isLoadingMethods || paymentMethods.length === 0}
          >
            <option value="">
              {isLoadingMethods
                ? t('common:loading')
                : paymentMethods.length === 0
                  ? t('income:form.noPaymentMethods')
                  : t('common:select')}
            </option>
            {paymentMethods.map((method) => (
              <option key={method.id} value={method.id}>
                {method.name}
              </option>
            ))}
          </Select>
        </FormField>

        <FormField label={t('income:form.paymentRepository')} htmlFor="payment_repository_id">
          <Select
            id="payment_repository_id"
            {...register('payment_repository_id')}
            disabled={isLoadingRepositories || paymentRepositories.length === 0}
          >
            <option value="">
              {isLoadingRepositories
                ? t('common:loading')
                : paymentRepositories.length === 0
                  ? t('income:form.noRepositories')
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
          <Checkbox {...register('is_received')} id="is_received" />
          <label htmlFor="is_received" className={`ms-2 text-sm ${textColors.secondary}`}>
            {t('income:form.isReceived')}
          </label>
        </div>
      </div>

      {/* Notes */}
      <FormField label={t('income:form.notes')} htmlFor="notes">
        <Textarea
          id="notes"
          {...register('notes')}
          rows={3}
          placeholder={t('income:form.notesPlaceholder')}
        />
      </FormField>

      {/* Actions */}
      <StickyFormFooter>
        {onCancel && (
          <Button type="button" variant="secondary" onClick={onCancel} disabled={isSubmitting}>
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
