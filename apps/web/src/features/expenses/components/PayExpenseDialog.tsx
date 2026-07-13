import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Modal, ModalContent, ModalFooter, ModalHeader } from '@/components/organisms/Modal'
import { cn } from '@/lib/utils'
import { semanticColorTokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { useActivePaymentMethods } from '../../treasury/hooks/usePaymentMethods'
import { useActivePaymentRepositories } from '../../treasury/hooks/usePaymentRepositories'
import { usePayExpense } from '../hooks/useExpenses'
import type { Expense, PayExpenseRequest } from '../types'

interface PayExpenseFormData {
  payment_repository_id: string
  payment_method_id: string
  payment_date: string
}

export interface PayExpenseDialogProps {
  isOpen: boolean
  onClose: () => void
  expense: Expense
  onSuccess?: (expense: Expense) => void
}

function defaultValues(): PayExpenseFormData {
  return {
    payment_repository_id: '',
    payment_method_id: '',
    payment_date: format(new Date(), 'yyyy-MM-dd'),
  }
}

export function PayExpenseDialog({
  isOpen,
  onClose,
  expense,
  onSuccess,
}: PayExpenseDialogProps) {
  const { t } = useTranslation(['expenses', 'common'])
  const payExpense = usePayExpense()
  const { data: repositories, isLoading: repositoriesLoading } =
    useActivePaymentRepositories()
  const { data: methods, isLoading: methodsLoading } = useActivePaymentMethods()
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<PayExpenseFormData>({ defaultValues: defaultValues() })

  useEffect(() => {
    if (isOpen) reset(defaultValues())
  }, [isOpen, reset])

  const onSubmit = (form: PayExpenseFormData) => {
    const data: PayExpenseRequest = {
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
                {repositories.map((repository) => (
                  <option key={repository.id} value={repository.id}>
                    {repository.name}
                  </option>
                ))}
              </Select>
            </FormField>

            <FormField label={t('expenses:pay.method')} htmlFor="pay-expense-method">
              <Select
                id="pay-expense-method"
                {...register('payment_method_id')}
                disabled={methodsLoading || payExpense.isPending}
              >
                <option value="">{t('expenses:pay.noMethod')}</option>
                {methods.map((method) => (
                  <option key={method.id} value={method.id}>
                    {method.name}
                  </option>
                ))}
              </Select>
            </FormField>

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
