import { useTranslation } from 'react-i18next'
import { useForm, useWatch, type UseFormSetValue } from 'react-hook-form'

import {
  Button,
  FormField,
  Input,
  MoneyInput,
  QuantityInput,
  Select,
  Textarea,
} from '@/components/atoms'
import { PartnerPicker } from '@/components/molecules/pickers'
import { Modal } from '@/components/organisms/Modal'
import { useCurrency } from '@/hooks/useCurrency'
import { useTaxConfigurations } from '@/hooks/useTaxConfigurations'
import { tokens } from '@/lib/designTokens'
import { useActivePaymentMethods } from '@/features/treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '@/features/treasury/hooks/usePaymentRepositories'

import { useExpenseCategories } from '../../hooks/useExpenseCategories'
import {
  useCreateExpenseRecurrence,
  useUpdateExpenseRecurrence,
} from '../../hooks/useExpenseRecurrences'
import type {
  CreateExpenseRecurrenceDTO,
  ExpenseRecurrenceTemplate,
  RecurrenceFrequency,
} from '../../types'

interface ExpenseRecurrenceFormProps {
  editing: ExpenseRecurrenceTemplate | null
  onClose: () => void
}

function emptyForm(): CreateExpenseRecurrenceDTO {
  return {
    name: '',
    expense_category_id: null,
    partner_id: null,
    payment_method_id: null,
    payment_repository_id: null,
    vendor_name: '',
    amount: '',
    vat_rate: null,
    vat_deductible_percent: '100',
    vat_amount: null,
    notes: '',
    frequency: 'monthly',
    start_date: new Date().toISOString().slice(0, 10),
    end_date: null,
    lead_days: 3,
  }
}

function templateForm(template: ExpenseRecurrenceTemplate): CreateExpenseRecurrenceDTO {
  return {
    name: template.name,
    expense_category_id: template.expense_category_id,
    partner_id: template.partner_id,
    payment_method_id: template.payment_method_id,
    payment_repository_id: template.payment_repository_id,
    vendor_name: template.vendor_name,
    amount: template.amount,
    vat_rate: template.vat_rate,
    vat_deductible_percent: template.vat_deductible_percent ?? '100',
    vat_amount: template.vat_amount,
    notes: template.notes,
    frequency: template.frequency,
    start_date: template.start_date,
    end_date: template.end_date,
    lead_days: template.lead_days,
  }
}

function nullableValue(value: string | null | undefined): string | null {
  return value === undefined || value === null || value === '' ? null : value
}

function isRecurrenceFrequency(value: string): value is RecurrenceFrequency {
  return value === 'monthly' || value === 'quarterly' || value === 'yearly'
}

interface ScheduleFieldsProps {
  form: CreateExpenseRecurrenceDTO
  setValue: UseFormSetValue<CreateExpenseRecurrenceDTO>
}

function ScheduleFields({ form, setValue }: ScheduleFieldsProps) {
  const { t } = useTranslation('expenses')

  return (
    <section className="space-y-4">
      <h3 className={tokens.heading.section}>{t('recurrences.schedule')}</h3>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label={t('recurrences.name')} htmlFor="recurrence-name" required>
          <Input
            id="recurrence-name"
            value={form.name}
            onChange={(event) => {
              setValue('name', event.target.value)
            }}
            required
          />
        </FormField>
        <FormField label={t('recurrences.frequencyLabel')} htmlFor="recurrence-frequency" required>
          <Select
            id="recurrence-frequency"
            value={form.frequency}
            onChange={(event) => {
              const frequency = event.target.value
              if (isRecurrenceFrequency(frequency)) {
                setValue('frequency', frequency)
              }
            }}
          >
            {(['monthly', 'quarterly', 'yearly'] as const).map((frequency) => (
              <option key={frequency} value={frequency}>
                {t(`recurrences.frequency.${frequency}`)}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label={t('recurrences.startDate')} htmlFor="recurrence-start" required>
          <Input
            id="recurrence-start"
            type="date"
            value={form.start_date}
            onChange={(event) => {
              setValue('start_date', event.target.value)
            }}
            required
          />
        </FormField>
        <FormField label={t('recurrences.endDate')} htmlFor="recurrence-end">
          <Input
            id="recurrence-end"
            type="date"
            min={form.start_date}
            value={form.end_date ?? ''}
            onChange={(event) => {
              setValue('end_date', nullableValue(event.target.value))
            }}
          />
        </FormField>
        <FormField
          label={t('recurrences.leadDays')}
          htmlFor="recurrence-lead"
          helperText={t('recurrences.leadDaysHint')}
        >
          <Input
            id="recurrence-lead"
            type="number"
            min="0"
            max="60"
            value={String(form.lead_days)}
            onChange={(event) => {
              setValue(
                'lead_days',
                Number.parseInt(event.target.value === '' ? '0' : event.target.value, 10),
              )
            }}
          />
        </FormField>
      </div>
    </section>
  )
}

export function ExpenseRecurrenceForm({ editing, onClose }: ExpenseRecurrenceFormProps) {
  const { t } = useTranslation(['expenses', 'common'])
  const { currency } = useCurrency()
  const { data: categories = [] } = useExpenseCategories()
  const { data: paymentMethods = [] } = useActivePaymentMethods()
  const { data: paymentRepositories = [] } = useActivePaymentRepositories()
  const { data: taxConfigurations = [] } = useTaxConfigurations()
  const createTemplate = useCreateExpenseRecurrence()
  const updateTemplate = useUpdateExpenseRecurrence()
  const { control, handleSubmit, setValue } = useForm<CreateExpenseRecurrenceDTO>({
    defaultValues: editing ? templateForm(editing) : emptyForm(),
  })
  const form = useWatch({
    control,
    compute: (values): CreateExpenseRecurrenceDTO => ({
      name: values.name,
      expense_category_id: values.expense_category_id ?? null,
      partner_id: values.partner_id ?? null,
      payment_method_id: values.payment_method_id ?? null,
      payment_repository_id: values.payment_repository_id ?? null,
      vendor_name: values.vendor_name ?? null,
      amount: values.amount,
      vat_rate: values.vat_rate ?? null,
      vat_deductible_percent: values.vat_deductible_percent ?? null,
      vat_amount: values.vat_amount ?? null,
      notes: values.notes ?? null,
      frequency: values.frequency,
      start_date: values.start_date,
      end_date: values.end_date ?? null,
      lead_days: values.lead_days,
    }),
  })

  const isSaving = createTemplate.isPending || updateTemplate.isPending
  const percentageRates = taxConfigurations.filter((configuration) =>
    configuration.is_active &&
    configuration.tax_type === 'PERCENTAGE' &&
    configuration.applies_to === 'LINE_ITEMS' &&
    configuration.percentage_rate !== null
  )
  const hasHistoricalVatRate = form.vat_rate !== null && form.vat_rate !== '' &&
    !percentageRates.some((configuration) => configuration.percentage_rate === form.vat_rate)

  const save = async (values: CreateExpenseRecurrenceDTO) => {
    const payload: CreateExpenseRecurrenceDTO = {
      ...values,
      expense_category_id: nullableValue(values.expense_category_id),
      partner_id: nullableValue(values.partner_id),
      payment_method_id: nullableValue(values.payment_method_id),
      payment_repository_id: nullableValue(values.payment_repository_id),
      vendor_name: nullableValue(values.vendor_name),
      vat_rate: nullableValue(values.vat_rate),
      vat_deductible_percent: nullableValue(values.vat_deductible_percent),
      vat_amount: nullableValue(values.vat_amount),
      notes: nullableValue(values.notes),
      end_date: nullableValue(values.end_date),
    }

    try {
      if (editing) {
        await updateTemplate.mutateAsync({ id: editing.id, data: payload })
      } else {
        await createTemplate.mutateAsync(payload)
      }
      onClose()
    } catch {
      // Mutation hooks surface the API error.
    }
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={editing ? t('expenses:recurrences.edit') : t('expenses:recurrences.create')}
      size="xl"
      className="max-h-[calc(100vh-2rem)] overflow-y-auto"
    >
      <form onSubmit={(event) => { void handleSubmit(save)(event) }}>
        <Modal.Content className="p-5 sm:p-6">
          <ScheduleFields form={form} setValue={setValue} />

          <section className="space-y-4">
            <h3 className={tokens.heading.section}>{t('expenses:form.vendorInfo')}</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <PartnerPicker
                value={form.partner_id ?? null}
                onChange={(value) => {
                  const partner = typeof value === 'string' ? null : value
                  setValue('partner_id', partner?.id ?? null)
                  setValue('vendor_name', partner?.name ?? form.vendor_name ?? null)
                }}
                partnerType="supplier"
                label={t('expenses:form.supplier')}
                placeholder={t('expenses:form.supplierPlaceholder')}
              />
              <FormField label={t('expenses:form.vendorName')} htmlFor="recurrence-vendor">
                <Input
                  id="recurrence-vendor"
                  value={form.vendor_name ?? ''}
                  onChange={(event) => {
                    setValue('vendor_name', event.target.value)
                  }}
                />
              </FormField>
              <FormField label={t('expenses:form.category')} htmlFor="recurrence-category">
                <Select
                  id="recurrence-category"
                  value={form.expense_category_id ?? ''}
                  onChange={(event) => {
                    setValue('expense_category_id', nullableValue(event.target.value))
                  }}
                >
                  <option value="">{t('common:none')}</option>
                  {categories.map((category) => (
                    <option key={category.id} value={category.id}>{category.name}</option>
                  ))}
                </Select>
              </FormField>
            </div>
          </section>

          <section className="space-y-4">
            <h3 className={tokens.heading.section}>{t('expenses:form.vatBreakdown')}</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label={t('expenses:recurrences.amount')} htmlFor="recurrence-amount" required>
                <MoneyInput
                  id="recurrence-amount"
                  aria-label={t('expenses:recurrences.amount')}
                  currency={currency}
                  value={form.amount}
                  onChange={(amount) => {
                    setValue('amount', amount)
                  }}
                  required
                />
              </FormField>
              <FormField label={t('expenses:form.vatRate')} htmlFor="recurrence-vat-rate">
                <QuantityInput
                  id="recurrence-vat-rate"
                  aria-label={t('expenses:form.vatRate')}
                  decimalPlaces={2}
                  max="100"
                  value={form.vat_rate ?? ''}
                  onChange={(vatRate) => {
                    setValue('vat_rate', nullableValue(vatRate))
                  }}
                  list="recurrence-vat-rates"
                />
                <datalist id="recurrence-vat-rates">
                  {percentageRates.map((configuration) => (
                    <option key={configuration.id} value={configuration.percentage_rate ?? ''} />
                  ))}
                  {hasHistoricalVatRate && <option value={form.vat_rate ?? ''} />}
                </datalist>
              </FormField>
              <FormField label={t('expenses:form.vatAmount')} htmlFor="recurrence-vat-amount">
                <MoneyInput
                  id="recurrence-vat-amount"
                  currency={currency}
                  value={form.vat_amount ?? ''}
                  onChange={(vatAmount) => {
                    setValue('vat_amount', nullableValue(vatAmount))
                  }}
                />
              </FormField>
              <FormField
                label={t('expenses:form.vatDeductible')}
                htmlFor="recurrence-vat-deductible"
                helperText={t('expenses:form.vatDeductibleHint')}
              >
                <QuantityInput
                  id="recurrence-vat-deductible"
                  aria-label={t('expenses:form.vatDeductible')}
                  decimalPlaces={2}
                  max="100"
                  value={form.vat_deductible_percent ?? ''}
                  onChange={(vatDeductiblePercent) => {
                    setValue('vat_deductible_percent', nullableValue(vatDeductiblePercent))
                  }}
                />
              </FormField>
            </div>
          </section>

          <section className="space-y-4">
            <h3 className={tokens.heading.section}>{t('expenses:form.paymentDetails')}</h3>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label={t('expenses:form.paymentMethod')} htmlFor="recurrence-payment-method">
                <Select
                  id="recurrence-payment-method"
                  value={form.payment_method_id ?? ''}
                  onChange={(event) => {
                    setValue('payment_method_id', nullableValue(event.target.value))
                  }}
                >
                  <option value="">{t('common:none')}</option>
                  {paymentMethods.map((method) => (
                    <option key={method.id} value={method.id}>{method.name}</option>
                  ))}
                </Select>
              </FormField>
              <FormField label={t('expenses:form.paymentRepository')} htmlFor="recurrence-payment-repository">
                <Select
                  id="recurrence-payment-repository"
                  value={form.payment_repository_id ?? ''}
                  onChange={(event) => {
                    setValue('payment_repository_id', nullableValue(event.target.value))
                  }}
                >
                  <option value="">{t('common:none')}</option>
                  {paymentRepositories.map((repository) => (
                    <option key={repository.id} value={repository.id}>{repository.name}</option>
                  ))}
                </Select>
              </FormField>
            </div>
            <FormField label={t('expenses:form.notes')} htmlFor="recurrence-notes">
              <Textarea
                id="recurrence-notes"
                value={form.notes ?? ''}
                onChange={(event) => {
                  setValue('notes', event.target.value)
                }}
                rows={3}
              />
            </FormField>
          </section>
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isSaving}>
            {isSaving ? t('common:saving') : t('expenses:recurrences.save')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
