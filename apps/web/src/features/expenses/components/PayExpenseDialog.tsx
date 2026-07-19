import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Radio } from '@/components/atoms/Radio'
import { Select } from '@/components/atoms/Select'
import { BankPicker } from '@/components/molecules/pickers/BankPicker'
import { Modal, ModalContent, ModalFooter, ModalHeader } from '@/components/organisms/Modal'
import { useCompanyConfigOptional } from '@/contexts/CompanyConfigContext'
import type { Bank } from '@/hooks/useBanks'
import { cn } from '@/lib/utils'
import { semanticColorTokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { useActivePaymentMethods } from '../../treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '../../treasury/hooks/usePaymentRepositories'
import { usePayExpense } from '../hooks/useExpenses'
import type { Expense, PayExpenseRequest } from '../types'

interface PayExpenseFormData {
  mode: 'cash' | 'instrument'
  payment_repository_id: string
  payment_method_id: string
  payment_date: string
  instrument_kind: 'cheque' | 'effet'
  instrument_reference: string
  instrument_maturity_date: string
  instrument_drawer_name: string
}

export interface PayExpenseDialogProps {
  isOpen: boolean
  onClose: () => void
  expense: Expense
  onSuccess?: (expense: Expense) => void
}

function defaultValues(): PayExpenseFormData {
  return {
    mode: 'cash',
    payment_repository_id: '',
    payment_method_id: '',
    payment_date: format(new Date(), 'yyyy-MM-dd'),
    instrument_kind: 'cheque',
    instrument_reference: '',
    instrument_maturity_date: '',
    instrument_drawer_name: '',
  }
}

export function PayExpenseDialog({
  isOpen,
  onClose,
  expense,
  onSuccess,
}: PayExpenseDialogProps) {
  const { t } = useTranslation(['expenses', 'common'])
  const companyConfig = useCompanyConfigOptional()
  const payExpense = usePayExpense()
  const [selectedBank, setSelectedBank] = useState<Bank | null>(null)
  const { data: repositories, isLoading: repositoriesLoading } =
    useActivePaymentRepositories()
  const { data: methods, isLoading: methodsLoading } = useActivePaymentMethods()
  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors },
  } = useForm<PayExpenseFormData>({ defaultValues: defaultValues() })
  const mode = watch('mode')
  const instrumentKind = watch('instrument_kind')
  const availableRepositories = mode === 'instrument'
    ? repositories.filter((repository) => repository.type === 'bank_account')
    : repositories
  const availableMethods = mode === 'instrument'
    ? methods.filter((method) => method.instrument_kind === instrumentKind)
    : methods.filter((method) => method.instrument_kind === null || method.instrument_kind === 'other')

  useEffect(() => {
    if (isOpen) {
      reset(defaultValues())
      setSelectedBank(null)
    }
  }, [isOpen, reset])

  useEffect(() => {
    setValue('payment_repository_id', '')
    setValue('payment_method_id', '')
  }, [mode, setValue])

  useEffect(() => {
    if (mode === 'instrument') {
      setValue('payment_method_id', '')
      setValue('instrument_maturity_date', '')
    }
  }, [instrumentKind, mode, setValue])

  const onSubmit = (form: PayExpenseFormData) => {
    const data: PayExpenseRequest = form.mode === 'instrument'
      ? {
          mode: 'instrument',
          payment_repository_id: form.payment_repository_id,
          payment_method_id: form.payment_method_id,
          payment_date: form.payment_date,
          instrument: {
            kind: form.instrument_kind,
            reference: form.instrument_reference,
            bank_id: selectedBank?.id ?? null,
            maturity_date: form.instrument_kind === 'effet'
              ? form.instrument_maturity_date || null
              : null,
            drawer_name: form.instrument_drawer_name || null,
          },
        }
      : {
          payment_repository_id: form.payment_repository_id,
          payment_method_id: form.payment_method_id || null,
          payment_date: form.payment_date,
        }
    payExpense.mutate(
      { id: expense.id, data },
      {
        onSuccess: (paidExpense) => {
          onSuccess?.(paidExpense)
          onClose()
        },
      },
    )
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="md">
      <ModalHeader title={t('expenses:pay.title')} onClose={onClose} />
      <form onSubmit={(event) => { void handleSubmit(onSubmit)(event) }}>
        <ModalContent>
          <div className="space-y-4">
            <div className={cn(
              'rounded-lg border p-4',
              semanticColorTokens.border.subtle,
              semanticColorTokens.surface.muted,
            )}>
              <div className={cn('text-sm', textColors.tertiary)}>
                {t('expenses:pay.total')}
              </div>
              <div className={cn('mt-1 text-lg font-semibold tabular-nums', textColors.primary)}>
                {formatCurrency(expense.total, { currency: expense.currency })}
              </div>
            </div>

            <fieldset className="space-y-2">
              <legend className={cn('text-sm font-medium', textColors.secondary)}>
                {t('expenses:pay.mode')}
              </legend>
              <div className="grid grid-cols-2 gap-3">
                {(['cash', 'instrument'] as const).map((paymentMode) => (
                  <label
                    key={paymentMode}
                    className={cn(
                      'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm',
                      mode === paymentMode
                        ? `${semanticColorTokens.intent.primary.borderFocus} ${semanticColorTokens.intent.primary.bgSubtle}`
                        : semanticColorTokens.border.subtle,
                    )}
                  >
                    <Radio
                      value={paymentMode}
                      {...register('mode')}
                      disabled={payExpense.isPending}
                    />
                    {t(`expenses:pay.modes.${paymentMode}`)}
                  </label>
                ))}
              </div>
            </fieldset>

            <FormField
              label={t('expenses:pay.repository')}
              htmlFor="pay-expense-repository"
              required
              error={errors.payment_repository_id?.message}
            >
              <Select
                id="pay-expense-repository"
                {...register('payment_repository_id', {
                  required: t('expenses:pay.repositoryRequired'),
                })}
                disabled={repositoriesLoading || payExpense.isPending}
              >
                <option value="">{t('expenses:pay.selectRepository')}</option>
                {availableRepositories.map((repository) => (
                  <option key={repository.id} value={repository.id}>
                    {repository.name}
                  </option>
                ))}
              </Select>
            </FormField>

            <FormField
              label={t('expenses:pay.method')}
              htmlFor="pay-expense-method"
              required={mode === 'instrument'}
              error={errors.payment_method_id?.message}
            >
              <Select
                id="pay-expense-method"
                {...register('payment_method_id', {
                  validate: (value) => mode !== 'instrument'
                    || value !== ''
                    || t('expenses:pay.methodRequired'),
                })}
                disabled={methodsLoading || payExpense.isPending}
              >
                <option value="">{t('expenses:pay.noMethod')}</option>
                {availableMethods.map((method) => (
                  <option key={method.id} value={method.id}>
                    {method.name}
                  </option>
                ))}
              </Select>
            </FormField>

            {mode === 'instrument' ? (
              <div className={cn(
                'space-y-4 rounded-lg border p-4',
                semanticColorTokens.border.subtle,
                semanticColorTokens.surface.page,
              )}>
                <div className="grid gap-4 sm:grid-cols-2">
                  <FormField
                    label={t('expenses:pay.instrument.kind')}
                    htmlFor="pay-expense-instrument-kind"
                    required
                  >
                    <Select
                      id="pay-expense-instrument-kind"
                      {...register('instrument_kind')}
                      disabled={payExpense.isPending}
                    >
                      <option value="cheque">{t('expenses:pay.instrument.kinds.cheque')}</option>
                      <option value="effet">{t('expenses:pay.instrument.kinds.effet')}</option>
                    </Select>
                  </FormField>

                  <FormField
                    label={t('expenses:pay.instrument.reference')}
                    htmlFor="pay-expense-instrument-reference"
                    required
                    error={errors.instrument_reference?.message}
                  >
                    <Input
                      id="pay-expense-instrument-reference"
                      {...register('instrument_reference', {
                        required: t('expenses:pay.instrument.referenceRequired'),
                      })}
                      disabled={payExpense.isPending}
                    />
                  </FormField>
                </div>

                <FormField
                  label={t('expenses:pay.instrument.bank')}
                  htmlFor="pay-expense-instrument-bank"
                >
                  <BankPicker
                    id="pay-expense-instrument-bank"
                    aria-label={t('expenses:pay.instrument.bank')}
                    country={companyConfig?.config?.country_code ?? ''}
                    value={selectedBank}
                    onChange={setSelectedBank}
                    isFallback={false}
                    fallbackValue=""
                    onFallbackChange={() => undefined}
                    onFallbackValueChange={() => undefined}
                    allowFallback={false}
                    disabled={payExpense.isPending}
                  />
                </FormField>

                <div className="grid gap-4 sm:grid-cols-2">
                  {instrumentKind === 'effet' ? (
                    <FormField
                      label={t('expenses:pay.instrument.maturityDate')}
                      htmlFor="pay-expense-instrument-maturity"
                      required
                      error={errors.instrument_maturity_date?.message}
                    >
                      <Input
                        id="pay-expense-instrument-maturity"
                        type="date"
                        {...register('instrument_maturity_date', {
                          validate: (value) => value !== ''
                            || t('expenses:pay.instrument.maturityDateRequired'),
                        })}
                        disabled={payExpense.isPending}
                      />
                    </FormField>
                  ) : null}

                  <FormField
                    label={t('expenses:pay.instrument.drawerName')}
                    htmlFor="pay-expense-instrument-drawer"
                  >
                    <Input
                      id="pay-expense-instrument-drawer"
                      {...register('instrument_drawer_name')}
                      disabled={payExpense.isPending}
                    />
                  </FormField>
                </div>
              </div>
            ) : null}

            <FormField
              label={t('expenses:pay.date')}
              htmlFor="pay-expense-date"
              required
              error={errors.payment_date?.message}
            >
              <Input
                id="pay-expense-date"
                type="date"
                {...register('payment_date', { required: t('expenses:pay.dateRequired') })}
                disabled={payExpense.isPending}
              />
            </FormField>
          </div>
        </ModalContent>

        <ModalFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={onClose}
            disabled={payExpense.isPending}
          >
            {t('common:cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={payExpense.isPending}>
            {payExpense.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            {t('expenses:pay.submit')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
