import { useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Plus, Trash2 } from 'lucide-react'
import { api } from '../../lib/api'

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
  totalAmount: number
  currency: string
  onSuccess: () => void
  onCancel: () => void
}

export function SplitPaymentForm({
  documentId,
  totalAmount,
  currency,
  onSuccess,
  onCancel,
}: SplitPaymentFormProps) {
  const { t } = useTranslation(['treasury', 'common'])

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
    queryKey: ['payment-methods'],
    queryFn: async () => {
      const response = await api.get<{ data: PaymentMethod[] }>('/payment-methods')
      return response.data
    },
  })

  const { data: repositoriesData } = useQuery({
    queryKey: ['payment-repositories'],
    queryFn: async () => {
      const response = await api.get<{ data: Repository[] }>('/payment-repositories')
      return response.data
    },
  })

  const submitMutation = useMutation({
    mutationFn: async (splits: Array<{ payment_method_id: string; amount: string; repository_id?: string; reference?: string }>) => {
      return api.post(`/documents/${documentId}/split-payment`, { splits })
    },
    onSuccess: () => {
      onSuccess()
    },
  })

  const paymentMethods = paymentMethodsData?.data ?? []
  const repositories = repositoriesData?.data ?? []

  const currentTotal = paymentLines.reduce((sum, line) => {
    const amount = parseFloat(line.amount) || 0
    return sum + amount
  }, 0)

  const remaining = totalAmount - currentTotal

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

  const handleSubmit = () => {
    // Validate amounts match
    if (Math.abs(currentTotal - totalAmount) > 0.01) {
      setValidationError(t('treasury:splitPayment.amountDoesNotMatch'))
      return
    }

    // Validate all lines have required fields
    const invalidLines = paymentLines.filter(
      (line) => !line.payment_method_id || !line.amount || parseFloat(line.amount) <= 0
    )
    if (invalidLines.length > 0) {
      setValidationError(t('treasury:splitPayment.incompleteLines'))
      return
    }

    const splits = paymentLines.map((line) => {
      const split: { payment_method_id: string; amount: string; repository_id?: string; reference?: string } = {
        payment_method_id: line.payment_method_id,
        amount: line.amount,
      }
      if (line.repository_id) {
        split.repository_id = line.repository_id
      }
      if (line.reference) {
        split.reference = line.reference
      }
      return split
    })

    submitMutation.mutate(splits)
  }

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency,
    }).format(amount)
  }

  return (
    <div className="space-y-6">
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div className="flex justify-between items-center">
          <div>
            <span className="text-sm font-medium text-gray-500">
              {t('treasury:splitPayment.totalRequired')}
            </span>
            <div className="text-xl font-bold text-gray-900">
              {formatCurrency(totalAmount)}
            </div>
          </div>
          <div className="text-end">
            <span className="text-sm font-medium text-gray-500">
              {t('treasury:splitPayment.remaining')}
            </span>
            <div
              className={`text-xl font-bold ${
                Math.abs(remaining) < 0.01
                  ? 'text-green-600'
                  : remaining > 0
                  ? 'text-yellow-600'
                  : 'text-red-600'
              }`}
            >
              {formatCurrency(remaining)}
            </div>
          </div>
        </div>
      </div>

      <div className="space-y-4">
        {paymentLines.map((line, index) => (
          <div
            key={line.id}
            className="rounded-lg border border-gray-200 bg-white p-4"
          >
            <div className="flex items-center justify-between mb-4">
              <h4 className="text-sm font-medium text-gray-700">
                {t('treasury:splitPayment.paymentLine', { number: index + 1 })}
              </h4>
              {paymentLines.length > 1 && (
                <button
                  type="button"
                  onClick={() => { removePaymentLine(line.id); }}
                  className="text-red-600 hover:text-red-800"
                  aria-label={t('common:actions.remove')}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label
                  htmlFor={`method-${line.id}`}
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('treasury:payments.method')}
                </label>
                <select
                  id={`method-${line.id}`}
                  value={line.payment_method_id}
                  onChange={(e) =>
                    { updatePaymentLine(line.id, 'payment_method_id', e.target.value); }
                  }
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  aria-label={t('treasury:payments.method')}
                >
                  <option value="">{t('common:select')}</option>
                  {paymentMethods.map((method) => (
                    <option key={method.id} value={method.id}>
                      {method.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label
                  htmlFor={`amount-${line.id}`}
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('treasury:payments.amount')}
                </label>
                <input
                  type="number"
                  id={`amount-${line.id}`}
                  value={line.amount}
                  onChange={(e) =>
                    { updatePaymentLine(line.id, 'amount', e.target.value); }
                  }
                  min="0.01"
                  step="0.01"
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  placeholder="0.00"
                  aria-label={t('treasury:payments.amount')}
                />
              </div>

              <div>
                <label
                  htmlFor={`repository-${line.id}`}
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('treasury:instruments.repository')}
                </label>
                <select
                  id={`repository-${line.id}`}
                  value={line.repository_id}
                  onChange={(e) =>
                    { updatePaymentLine(line.id, 'repository_id', e.target.value); }
                  }
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">{t('common:select')}</option>
                  {repositories.map((repo) => (
                    <option key={repo.id} value={repo.id}>
                      {repo.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label
                  htmlFor={`reference-${line.id}`}
                  className="block text-sm font-medium text-gray-700 mb-1"
                >
                  {t('treasury:payments.reference')}
                </label>
                <input
                  type="text"
                  id={`reference-${line.id}`}
                  value={line.reference}
                  onChange={(e) =>
                    { updatePaymentLine(line.id, 'reference', e.target.value); }
                  }
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  placeholder={t('treasury:payments.reference')}
                />
              </div>
            </div>
          </div>
        ))}
      </div>

      <button
        type="button"
        onClick={addPaymentLine}
        className="inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-800"
      >
        <Plus className="h-4 w-4" />
        {t('treasury:splitPayment.addPayment')}
      </button>

      {validationError && (
        <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700">
          {validationError}
        </div>
      )}

      <div className="flex justify-end gap-3 pt-4 border-t border-gray-200">
        <button
          type="button"
          onClick={onCancel}
          className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
        >
          {t('common:actions.cancel')}
        </button>
        <button
          type="button"
          onClick={handleSubmit}
          disabled={submitMutation.isPending}
          className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
        >
          {submitMutation.isPending ? t('common:status.loading') : t('common:actions.submit')}
        </button>
      </div>
    </div>
  )
}
