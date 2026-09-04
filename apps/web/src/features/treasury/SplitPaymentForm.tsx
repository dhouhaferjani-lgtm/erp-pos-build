import { useRef, useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors, colors } from '../../lib/designTokens'
import { bcadd, bccomp, bcsub } from '../../lib/decimal'
import { useCurrency } from '../../hooks/useCurrency'
import { useIdempotencyKey } from '../../hooks/useIdempotencyKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { Button } from '../../components/atoms/Button/Button'
import { FormField } from '../../components/atoms/FormField/FormField'
import { Input } from '../../components/atoms/Input/Input'
import { MoneyInput } from '../../components/atoms/MoneyInput/MoneyInput'
import { Select } from '../../components/atoms/Select/Select'

interface PaymentMethod {
  id: string
  name: string
  code: string
  is_physical: boolean
}

interface Repository {
  id: string
  name: string
  code: string
  type: string
}

interface PaymentLine {
  id: string
  payment_method_id: string
  amount: string
  repository_id: string
  reference: string
}

interface SplitPaymentFormProps {
  documentId: string
  totalAmount: string
  currency?: string
  onSuccess: () => void
  onCancel: () => void
}

export function SplitPaymentForm({
  documentId,
  totalAmount,
  currency: _currency,
  onSuccess,
  onCancel,
}: SplitPaymentFormProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const { currency, format: formatCurrencyHook } = useCurrency()
  const { key: idempotencyKey, reset: resetIdempotencyKey } = useIdempotencyKey()
  const submitLockRef = useRef<boolean>(false)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [paymentLines, setPaymentLines] = useState<PaymentLine[]>([
    {
      id: crypto.randomUUID(),
      payment_method_id: '',
      amount: '',
      repository_id: '',
      reference: '',
    },
  ])
  const [validationError, setValidationError] = useState<string | null>(null)

  const { data: paymentMethodsData } = useQuery({
    queryKey: tenantScopedKey(['payment-methods']),
    queryFn: async () => {
      const response = await api.get<{ data: PaymentMethod[] }>('/payment-methods')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const { data: repositoriesData } = useQuery({
    queryKey: tenantScopedKey(['payment-repositories']),
    queryFn: async () => {
      const response = await api.get<{ data: Repository[] }>('/payment-repositories')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const submitMutation = useMutation({
    mutationFn: async (
      splits: Array<{
        payment_method_id: string
        amount: string
        repository_id?: string
        reference?: string
      }>,
    ) => api.post(`/documents/${documentId}/split-payment`, {
      splits,
      idempotency_key: idempotencyKey,
    }),
    onSuccess: () => {
      resetIdempotencyKey()
      onSuccess()
    },
  })

  const paymentMethods = paymentMethodsData?.data ?? []
  const repositories = repositoriesData?.data ?? []

  const currentTotal = paymentLines.reduce(
    (sum, line) => bcadd(sum, line.amount === '' ? '0' : line.amount, 3),
    '0.000',
  )
  const remaining = bcsub(totalAmount, currentTotal, 3)

  const addPaymentLine = () => {
    setPaymentLines([
      ...paymentLines,
      {
        id: crypto.randomUUID(),
        payment_method_id: '',
        amount: '',
        repository_id: '',
        reference: '',
      },
    ])
    setValidationError(null)
  }

  const removePaymentLine = (id: string) => {
    if (paymentLines.length > 1) {
      setPaymentLines(paymentLines.filter((line) => line.id !== id))
      setValidationError(null)
    }
  }

  const updatePaymentLine = (id: string, field: keyof PaymentLine, value: string) => {
    setPaymentLines(
      paymentLines.map((line) =>
        line.id === id ? { ...line, [field]: value } : line
      )
    )
    setValidationError(null)
  }

  const handleSubmit = (): void => {
    if (submitLockRef.current) return
    if (bccomp(currentTotal, totalAmount) !== 0) {
      setValidationError(t('treasury:splitPayment.amountDoesNotMatch'))
      return
    }
    const invalidLines = paymentLines.filter(
      (line) => !line.payment_method_id || line.amount === '' || bccomp(line.amount, '0') <= 0,
    )
    if (invalidLines.length > 0) {
      setValidationError(t('treasury:splitPayment.incompleteLines'))
      return
    }
    const splits = paymentLines.map((line) => ({
      payment_method_id: line.payment_method_id,
      amount: line.amount,
      ...(line.repository_id ? { repository_id: line.repository_id } : {}),
      ...(line.reference ? { reference: line.reference } : {}),
    }))
    submitLockRef.current = true
    submitMutation.mutate(splits, {
      onSettled: () => { submitLockRef.current = false },
    })
  }

  const formatCurrency = (amount: string): string => formatCurrencyHook(amount)

  return (
    <div className="space-y-6">
      <div className={cn('rounded-lg border p-4', borderColors.light, colors.neutral[50])}>
        <div className="flex justify-between items-center">
          <div>
            <span className={cn('text-sm font-medium', textColors.tertiary)}>
              {t('treasury:splitPayment.totalRequired')}
            </span>
            <div className={cn('text-xl font-bold', textColors.primary)}>
              {formatCurrency(totalAmount)}
            </div>
          </div>
          <div className="text-end">
            <span className={cn('text-sm font-medium', textColors.tertiary)}>
              {t('treasury:splitPayment.remaining')}
            </span>
            <div
              data-testid="split-payment-remaining"
              className={cn(
                'text-xl font-bold',
                bccomp(remaining, '0') === 0
                  ? textColors.success
                  : bccomp(remaining, '0') > 0
                  ? textColors.warningDark
                  : textColors.error,
              )}
            >
              {formatCurrency(remaining)}
            </div>
          </div>
        </div>
      </div>

      <div className="space-y-4">
        {paymentLines.map((line, index) => (
          <div key={line.id} className={tokens.card.base}>
            <div className="flex items-center justify-between mb-4">
              <h4 className={cn('text-sm font-medium', textColors.secondary)}>
                {t('treasury:splitPayment.paymentLine', { number: index + 1 })}
              </h4>
              {paymentLines.length > 1 && (
                <button
                  type="button"
                  onClick={() => { removePaymentLine(line.id); }}
                  className={cn(textColors.error, textColors.hoverError)}
                  aria-label={t('common:actions.remove')}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('treasury:payments.method')} htmlFor={`method-${line.id}`}>
                <Select
                  id={`method-${line.id}`}
                  value={line.payment_method_id}
                  onChange={(e) =>
                    { updatePaymentLine(line.id, 'payment_method_id', e.target.value); }
                  }
                  aria-label={t('treasury:payments.method')}
                >
                  <option value="">{t('common:select')}</option>
                  {paymentMethods.map((method) => (
                    <option key={method.id} value={method.id}>
                      {method.name}
                    </option>
                  ))}
                </Select>
              </FormField>

              <FormField label={t('treasury:payments.amount')} htmlFor={`amount-${line.id}`}>
                <MoneyInput
                  id={`amount-${line.id}`}
                  currency={currency}
                  value={line.amount}
                  onChange={(v) => { updatePaymentLine(line.id, 'amount', v) }}
                  min="0.01"
                  aria-label={t('treasury:payments.amount')}
                />
              </FormField>

              <FormField label={t('treasury:instruments.repository')} htmlFor={`repository-${line.id}`}>
                <Select
                  id={`repository-${line.id}`}
                  value={line.repository_id}
                  onChange={(e) =>
                    { updatePaymentLine(line.id, 'repository_id', e.target.value); }
                  }
                >
                  <option value="">{t('common:select')}</option>
                  {repositories.map((repo) => (
                    <option key={repo.id} value={repo.id}>
                      {repo.name}
                    </option>
                  ))}
                </Select>
              </FormField>

              <FormField label={t('treasury:payments.reference')} htmlFor={`reference-${line.id}`}>
                <Input
                  type="text"
                  id={`reference-${line.id}`}
                  value={line.reference}
                  onChange={(e) =>
                    { updatePaymentLine(line.id, 'reference', e.target.value); }
                  }
                  placeholder={t('treasury:payments.reference')}
                />
              </FormField>
            </div>
          </div>
        ))}
      </div>

      <button
        type="button"
        onClick={addPaymentLine}
        className={cn('inline-flex items-center gap-2 text-sm font-medium transition-colors', textColors.brand)}
      >
        <Plus className="h-4 w-4" />
        {t('treasury:splitPayment.addPayment')}
      </button>

      {validationError && (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {validationError}
        </div>
      )}

      <div className={cn('flex justify-end gap-3 pt-4 border-t', borderColors.light)}>
        <Button type="button" variant="secondary" onClick={onCancel}>
          {t('common:actions.cancel')}
        </Button>
        <Button
          type="button"
          variant="primary"
          onClick={handleSubmit}
          disabled={submitMutation.isPending}
        >
          {submitMutation.isPending ? t('common:status.loading') : t('common:actions.submit')}
        </Button>
      </div>
    </div>
  )
}
