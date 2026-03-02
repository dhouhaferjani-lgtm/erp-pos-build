import { useEffect, useState, useMemo, useCallback } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2, Plus, CheckCircle, Trash2, Check } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../Modal'
import { FormField } from '../../atoms/FormField'
import { Input } from '../../atoms/Input'
import { Select } from '../../atoms/Select'
import { Textarea } from '../../atoms/Textarea'
import { Button } from '../../atoms/Button'
import { api, apiPost } from '../../../lib/api'
import { useCurrency } from '../../../hooks/useCurrency'
import { AddRepositoryModal } from '../AddRepositoryModal'

interface PaymentMethod {
  id: string
  code: string
  name: string
  is_physical: boolean
  has_maturity: boolean
}

interface Repository {
  id: string
  code: string
  name: string
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual'
}

interface PaymentMethodsResponse {
  data: PaymentMethod[]
}

interface RepositoriesResponse {
  data: Repository[]
}

interface OpenInvoice {
  id: string
  document_number: string
  balance_due: string
  due_date: string
  days_overdue?: number
}

interface OpenInvoicesResponse {
  data: OpenInvoice[]
}

interface PaymentLineData {
  id: string
  payment_method_id: string
  repository_id: string
  amount: string
  reference: string
  confirmed: boolean
}

interface ExcessAllocation {
  document_id: string
  amount: string
}

type ExcessAllocationMethod = 'fifo' | 'due_date' | 'manual' | 'advance'

// This is the shape returned by apiPost (already unwrapped from ApiResponse wrapper)
interface MultiPaymentResponseData {
  payments: Array<{
    id: string
    payment_number: string
    amount: string
  }>
  document: {
    id: string
    document_number: string
    balance_due: string
    status: string
  }
  excess_handling: {
    excess_amount: string
    allocation_method: string
    allocations: Array<{
      document_id: string
      document_number: string
      amount: string
    }>
  }
}

export interface InvoicePrefill {
  partner_id: string
  partner_name: string
  amount: number          // amount_residual, NOT total
  reference: string       // Invoice number
  document_id: string
  document_type: 'invoice' | 'sales_order' | 'purchase_order'
}

export interface RecordPaymentModalProps {
  isOpen: boolean
  onClose: () => void
  onSuccess?: () => void
  prefill: InvoicePrefill
}

export function RecordPaymentModal({
  isOpen,
  onClose,
  onSuccess,
  prefill,
}: RecordPaymentModalProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const { currency, symbol, format: formatCurrencyAmount } = useCurrency()
  const queryClient = useQueryClient()
  const [showRepositoryModal, setShowRepositoryModal] = useState(false)
  const [paymentDate, setPaymentDate] = useState(new Date().toISOString().split('T')[0])
  const [notes, setNotes] = useState('')
  const [paymentLines, setPaymentLines] = useState<PaymentLineData[]>([])
  const [excessAllocationMethod, setExcessAllocationMethod] = useState<ExcessAllocationMethod>('advance')
  const [manualAllocations, setManualAllocations] = useState<ExcessAllocation[]>([])
  const [validationError, setValidationError] = useState<string | null>(null)
  const [showSuccess, setShowSuccess] = useState(false)
  const [successData, setSuccessData] = useState<MultiPaymentResponseData | null>(null)

  // Reset form when modal opens
  useEffect(() => {
    if (isOpen) {
      setPaymentDate(new Date().toISOString().split('T')[0])
      setNotes(`Payment for ${prefill.document_type} ${prefill.reference}`)
      setPaymentLines([createNewPaymentLine()])
      setExcessAllocationMethod('advance')
      setManualAllocations([])
      setValidationError(null)
      setShowSuccess(false)
      setSuccessData(null)
    }
  }, [isOpen, prefill])

  const createNewPaymentLine = useCallback((): PaymentLineData => ({
    id: crypto.randomUUID(),
    payment_method_id: '',
    repository_id: '',
    amount: '',
    reference: '',
    confirmed: false,
  }), [])

  // Fetch payment methods
  const { data: paymentMethodsData } = useQuery({
    queryKey: ['payment-methods'],
    queryFn: async () => {
      const response = await api.get<PaymentMethodsResponse>('/payment-methods')
      return response.data
    },
    enabled: isOpen,
  })

  const paymentMethods = paymentMethodsData?.data ?? []

  // Fetch repositories
  const { data: repositoriesData } = useQuery({
    queryKey: ['payment-repositories'],
    queryFn: async () => {
      const response = await api.get<RepositoriesResponse>('/payment-repositories')
      return response.data
    },
    enabled: isOpen,
  })

  const repositories = repositoriesData?.data ?? []

  // Fetch open invoices for the partner (for excess allocation)
  const { data: openInvoicesData } = useQuery({
    queryKey: ['open-invoices', prefill.partner_id],
    queryFn: async () => {
      const response = await api.get<OpenInvoicesResponse>(`/partners/${prefill.partner_id}/open-invoices`)
      return response.data
    },
    enabled: isOpen && !!prefill.partner_id,
  })

  const openInvoices = useMemo(() => {
    const invoices = openInvoicesData?.data ?? []
    // Exclude the current document from open invoices list
    return invoices.filter(inv => inv.id !== prefill.document_id)
  }, [openInvoicesData, prefill.document_id])

  // Calculate totals
  const totalEntered = useMemo(() => {
    return paymentLines.reduce((sum, line) => {
      const amount = parseFloat(line.amount) || 0
      return sum + amount
    }, 0)
  }, [paymentLines])

  const balanceDue = prefill.amount
  const remaining = balanceDue - totalEntered
  const excessAmount = Math.max(0, -remaining)

  // Get compatible repository types based on payment method
  const getCompatibleRepositoryTypes = (method: PaymentMethod | undefined): Repository['type'][] => {
    if (!method) return ['cash_register', 'safe', 'bank_account', 'virtual']

    const code = method.code.toUpperCase()

    if (code === 'CASH' || code === 'ESPECES') {
      return ['cash_register', 'safe']
    }
    if (method.has_maturity || code === 'CHECK' || code === 'CHEQUE') {
      return ['safe']
    }
    if (code === 'BANK_TRANSFER' || code === 'VIREMENT' || code === 'CARD' || code === 'CARTE') {
      return ['bank_account']
    }
    if (code === 'PAYPAL' || code === 'STRIPE' || code === 'ONLINE') {
      return ['virtual', 'bank_account']
    }
    return ['cash_register', 'safe', 'bank_account', 'virtual']
  }

  // Filter repositories for a specific payment method
  const getFilteredRepositories = useCallback((paymentMethodId: string) => {
    const method = paymentMethods.find(m => m.id === paymentMethodId)
    const compatibleTypes = getCompatibleRepositoryTypes(method)
    return repositories.filter(repo => compatibleTypes.includes(repo.type))
  }, [paymentMethods, repositories])

  // Payment line handlers
  const addPaymentLine = () => {
    setPaymentLines([...paymentLines, createNewPaymentLine()])
    setValidationError(null)
  }

  const removePaymentLine = (id: string) => {
    if (paymentLines.length > 1) {
      setPaymentLines(paymentLines.filter(line => line.id !== id))
      setValidationError(null)
    }
  }

  const updatePaymentLine = (id: string, field: keyof PaymentLineData, value: string | boolean) => {
    setPaymentLines(prev => prev.map(line =>
      line.id === id ? { ...line, [field]: value } : line
    ))
    setValidationError(null)
  }

  // Update multiple fields at once (avoids race conditions)
  const updatePaymentLineMultiple = (id: string, updates: Partial<PaymentLineData>) => {
    setPaymentLines(prev => prev.map(line =>
      line.id === id ? { ...line, ...updates } : line
    ))
    setValidationError(null)
  }

  const confirmPaymentLine = (id: string) => {
    const line = paymentLines.find(l => l.id === id)
    if (!line) return

    // Validate the line before confirming
    if (!line.payment_method_id) {
      setValidationError(t('treasury:unifiedPayment.selectMethod'))
      return
    }
    if (!line.amount || parseFloat(line.amount) <= 0) {
      setValidationError(t('treasury:unifiedPayment.enterAmount'))
      return
    }

    updatePaymentLine(id, 'confirmed', true)
    setValidationError(null)
  }

  // Manual allocation handlers
  const updateManualAllocation = (documentId: string, amount: string) => {
    setManualAllocations(prev => {
      const existing = prev.find(a => a.document_id === documentId)
      if (existing) {
        if (!amount || parseFloat(amount) <= 0) {
          return prev.filter(a => a.document_id !== documentId)
        }
        return prev.map(a => a.document_id === documentId ? { ...a, amount } : a)
      }
      if (amount && parseFloat(amount) > 0) {
        return [...prev, { document_id: documentId, amount }]
      }
      return prev
    })
  }

  // Create multi-payment mutation
  const mutation = useMutation({
    mutationFn: async () => {
      const payments = paymentLines.filter(l => l.confirmed).map(line => ({
        payment_method_id: line.payment_method_id,
        repository_id: line.repository_id || undefined,
        amount: line.amount,
        reference: line.reference || undefined,
      }))

      // apiPost already unwraps the ApiResponse wrapper, so we get MultiPaymentResponseData directly
      return apiPost<MultiPaymentResponseData>('/payments', {
        partner_id: prefill.partner_id,
        document_id: prefill.document_id,
        currency,
        payment_date: paymentDate,
        payments,
        excess_allocation_method: excessAmount > 0 ? excessAllocationMethod : undefined,
        excess_allocations: excessAmount > 0 && excessAllocationMethod === 'manual' ? manualAllocations : undefined,
      })
    },
    onSuccess: (response) => {
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      void queryClient.invalidateQueries({ queryKey: ['invoice', prefill.document_id] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['open-invoices'] })
      void queryClient.invalidateQueries({ queryKey: ['document', prefill.document_id] })

      // response is already the unwrapped data (MultiPaymentResponseData)
      setSuccessData(response)
      setShowSuccess(true)
    },
    onError: (error) => {
      console.error('Payment recording failed:', error)
      // Error is shown via mutation.isError in the UI
    },
  })

  const handleSubmit = () => {
    // Validate at least one confirmed payment
    const confirmedLines = paymentLines.filter(l => l.confirmed)
    if (confirmedLines.length === 0) {
      setValidationError(t('treasury:unifiedPayment.confirmAtLeastOne'))
      return
    }

    mutation.mutate()
  }

  const handleFinalClose = () => {
    setShowSuccess(false)
    setSuccessData(null)
    onSuccess?.()
    onClose()
  }

  // Format currency
  const formatAmount = (amount: string | number): string => {
    return formatCurrencyAmount(amount)
  }

  const confirmedCount = paymentLines.filter(l => l.confirmed).length

  return (
    <>
      <Modal isOpen={isOpen} onClose={showSuccess ? handleFinalClose : onClose} size="lg">
        <ModalHeader
          title={showSuccess
            ? t('treasury:payments.paymentRecorded')
            : t('treasury:payments.recordPayment')}
          onClose={showSuccess ? handleFinalClose : onClose}
        />

        {showSuccess && successData ? (
          <>
            <ModalContent>
              <div className="rounded-lg bg-green-50 p-4 mb-4">
                <div className="flex items-start gap-3">
                  <CheckCircle className="h-5 w-5 text-green-600 flex-shrink-0 mt-0.5" />
                  <div>
                    <h4 className="text-sm font-medium text-green-900">
                      {t('treasury:payments.recordedSuccess')}
                    </h4>
                    <p className="mt-1 text-sm text-green-700">
                      {successData.payments.length} {t('treasury:unifiedPayment.paymentsRecorded')}
                    </p>
                  </div>
                </div>
              </div>

              <div className="space-y-4">
                <div className="rounded-lg border border-gray-200 p-4">
                  <h4 className="font-medium text-gray-900 mb-2">
                    {t('treasury:payments.documentStatus')}
                  </h4>
                  <div className="flex justify-between">
                    <span className="text-sm text-gray-600">{successData.document.document_number}</span>
                    <span className={`text-sm font-medium ${
                      successData.document.status === 'paid' ? 'text-green-600' : 'text-yellow-600'
                    }`}>
                      {successData.document.status === 'paid'
                        ? t('common:status.paid')
                        : `${t('treasury:payments.remaining')}: ${formatAmount(successData.document.balance_due)}`}
                    </span>
                  </div>
                </div>

                {parseFloat(successData.excess_handling.excess_amount) > 0 && (
                  <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-4">
                    <h4 className="font-medium text-yellow-900 mb-2">
                      {t('treasury:unifiedPayment.excessHandled')}
                    </h4>
                    <p className="text-sm text-yellow-700">
                      {formatAmount(successData.excess_handling.excess_amount)} - {
                        successData.excess_handling.allocation_method === 'advance'
                          ? t('treasury:unifiedPayment.keptAsAdvance')
                          : t('treasury:unifiedPayment.allocatedToInvoices')
                      }
                    </p>
                    {successData.excess_handling.allocations.length > 0 && (
                      <ul className="mt-2 text-sm text-yellow-700">
                        {successData.excess_handling.allocations.map(alloc => (
                          <li key={alloc.document_id}>
                            {alloc.document_number}: {formatAmount(alloc.amount)}
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}
              </div>
            </ModalContent>

            <ModalFooter>
              <Button type="button" variant="primary" onClick={handleFinalClose}>
                {t('common:actions.close')}
              </Button>
            </ModalFooter>
          </>
        ) : (
          <>
            <ModalContent>
              {/* Document context */}
              <div className="rounded-lg bg-blue-50 p-4 mb-4">
                <div className="flex items-center gap-2 text-sm text-blue-800">
                  <span className="font-medium">{t('treasury:payments.payingFor')}:</span>
                  <span>{prefill.reference}</span>
                  <span className="text-blue-600">({prefill.partner_name})</span>
                </div>
              </div>

              {/* Balance Summary */}
              <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 mb-4">
                <div className="grid grid-cols-3 gap-4 text-center">
                  <div>
                    <span className="text-sm font-medium text-gray-500 block">
                      {t('treasury:unifiedPayment.balanceDue')}
                    </span>
                    <span className="text-xl font-bold text-gray-900">
                      {formatAmount(balanceDue)}
                    </span>
                  </div>
                  <div>
                    <span className="text-sm font-medium text-gray-500 block">
                      {t('treasury:unifiedPayment.totalEntered')}
                    </span>
                    <span className="text-xl font-bold text-blue-600">
                      {formatAmount(totalEntered)}
                    </span>
                  </div>
                  <div>
                    <span className="text-sm font-medium text-gray-500 block">
                      {t('treasury:unifiedPayment.remaining')}
                    </span>
                    <span className={`text-xl font-bold ${
                      Math.abs(remaining) < 0.01 ? 'text-green-600' :
                      remaining > 0 ? 'text-yellow-600' : 'text-red-600'
                    }`}>
                      {formatAmount(remaining)}
                    </span>
                  </div>
                </div>
              </div>

              {/* Payment Date */}
              <div className="mb-4">
                <FormField label={t('treasury:payments.date')} htmlFor="payment-date" required>
                  <Input
                    id="payment-date"
                    type="date"
                    value={paymentDate}
                    onChange={e => { setPaymentDate(e.target.value); }}
                  />
                </FormField>
              </div>

              {/* Payment Lines */}
              <div className="space-y-4 mb-4">
                <h4 className="text-sm font-medium text-gray-700">
                  {t('treasury:unifiedPayment.paymentLines')} ({confirmedCount} {t('treasury:unifiedPayment.confirmed')})
                </h4>

                {paymentLines.map((line, index) => (
                  <div
                    key={line.id}
                    className={`rounded-lg border p-4 ${
                      line.confirmed ? 'border-green-300 bg-green-50' : 'border-gray-200 bg-white'
                    }`}
                  >
                    <div className="flex items-center justify-between mb-4">
                      <h5 className="text-sm font-medium text-gray-700">
                        {t('treasury:unifiedPayment.paymentLine', { number: index + 1 })}
                      </h5>
                      <div className="flex items-center gap-2">
                        {!line.confirmed ? (
                          <Button
                            type="button"
                            size="sm"
                            variant="primary"
                            onClick={() => { confirmPaymentLine(line.id); }}
                          >
                            <Check className="h-4 w-4 me-1" />
                            {t('common:actions.confirm')}
                          </Button>
                        ) : (
                          <span className="inline-flex items-center gap-1 text-sm font-medium text-green-700">
                            <CheckCircle className="h-4 w-4" />
                            {t('treasury:unifiedPayment.lineConfirmed')}
                          </span>
                        )}
                        {!line.confirmed && paymentLines.length > 1 && (
                          <button
                            type="button"
                            onClick={() => { removePaymentLine(line.id); }}
                            className="text-red-600 hover:text-red-800 p-1"
                            aria-label={t('common:actions.remove')}
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    </div>

                    <fieldset disabled={line.confirmed}>
                      <div className="grid grid-cols-2 gap-4">
                        <FormField
                          label={t('treasury:payments.method')}
                          htmlFor={`method-${line.id}`}
                          required
                        >
                          <Select
                            id={`method-${line.id}`}
                            value={line.payment_method_id}
                            onChange={e => {
                              // Use atomic update to prevent state race condition
                              updatePaymentLineMultiple(line.id, {
                                payment_method_id: e.target.value,
                                repository_id: '' // Reset repository when method changes
                              })
                            }}
                          >
                            <option value="">{t('common:actions.select')}</option>
                            {paymentMethods.map(method => (
                              <option key={method.id} value={method.id}>
                                {method.name}
                              </option>
                            ))}
                          </Select>
                        </FormField>

                        <FormField
                          label={t('treasury:payments.amount')}
                          htmlFor={`amount-${line.id}`}
                          required
                        >
                          <div className="space-y-2">
                            <div className="relative">
                              <span className="absolute start-3 top-1/2 -translate-y-1/2 text-gray-500">
                                {symbol}
                              </span>
                              <Input
                                id={`amount-${line.id}`}
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={line.amount}
                                onChange={e => { updatePaymentLine(line.id, 'amount', e.target.value); }}
                                className="ps-10"
                                placeholder="0.00"
                              />
                            </div>
                            {index === 0 && remaining > 0 && (
                              <button
                                type="button"
                                onClick={() => { updatePaymentLine(line.id, 'amount', remaining.toFixed(2)); }}
                                className="text-xs text-blue-600 hover:text-blue-800 font-medium"
                              >
                                {t('treasury:unifiedPayment.payFullAmount')} ({formatAmount(remaining)})
                              </button>
                            )}
                          </div>
                        </FormField>

                        <FormField
                          label={t('treasury:repositories.title')}
                          htmlFor={`repository-${line.id}`}
                        >
                          <Select
                            id={`repository-${line.id}`}
                            value={line.repository_id}
                            onChange={e => { updatePaymentLine(line.id, 'repository_id', e.target.value); }}
                          >
                            <option value="">{t('common:actions.select')}</option>
                            {getFilteredRepositories(line.payment_method_id).map(repo => (
                              <option key={repo.id} value={repo.id}>
                                {repo.name} ({repo.code})
                              </option>
                            ))}
                          </Select>
                        </FormField>

                        <FormField
                          label={t('treasury:payments.reference')}
                          htmlFor={`reference-${line.id}`}
                        >
                          <Input
                            id={`reference-${line.id}`}
                            value={line.reference}
                            onChange={e => { updatePaymentLine(line.id, 'reference', e.target.value); }}
                            placeholder={t('treasury:payments.referencePlaceholder')}
                          />
                        </FormField>
                      </div>
                    </fieldset>
                  </div>
                ))}

                <button
                  type="button"
                  onClick={addPaymentLine}
                  className="inline-flex items-center gap-2 text-sm font-medium text-blue-600 hover:text-blue-800"
                >
                  <Plus className="h-4 w-4" />
                  {t('treasury:unifiedPayment.addPaymentLine')}
                </button>
              </div>

              {/* Excess Allocation Panel */}
              {excessAmount > 0 && (
                <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-4 mb-4">
                  <h4 className="font-medium text-yellow-900 mb-3">
                    {t('treasury:unifiedPayment.excessPayment', { amount: formatAmount(excessAmount) })}
                  </h4>

                  <div className="space-y-3">
                    <p className="text-sm text-yellow-800">
                      {t('treasury:unifiedPayment.allocateExcess')}
                    </p>

                    <div className="space-y-2">
                      <label className="flex items-center gap-2">
                        <input
                          type="radio"
                          name="excessMethod"
                          value="advance"
                          checked={excessAllocationMethod === 'advance'}
                          onChange={() => { setExcessAllocationMethod('advance'); }}
                          className="text-blue-600"
                        />
                        <span className="text-sm text-gray-700">
                          {t('treasury:unifiedPayment.keepAsAdvance')}
                        </span>
                      </label>

                      {openInvoices.length > 0 && (
                        <>
                          <label className="flex items-center gap-2">
                            <input
                              type="radio"
                              name="excessMethod"
                              value="fifo"
                              checked={excessAllocationMethod === 'fifo'}
                              onChange={() => { setExcessAllocationMethod('fifo'); }}
                              className="text-blue-600"
                            />
                            <span className="text-sm text-gray-700">
                              {t('treasury:smartPayment.fifo')}
                            </span>
                          </label>

                          <label className="flex items-center gap-2">
                            <input
                              type="radio"
                              name="excessMethod"
                              value="due_date"
                              checked={excessAllocationMethod === 'due_date'}
                              onChange={() => { setExcessAllocationMethod('due_date'); }}
                              className="text-blue-600"
                            />
                            <span className="text-sm text-gray-700">
                              {t('treasury:smartPayment.dueDatePriority')}
                            </span>
                          </label>

                          <label className="flex items-center gap-2">
                            <input
                              type="radio"
                              name="excessMethod"
                              value="manual"
                              checked={excessAllocationMethod === 'manual'}
                              onChange={() => { setExcessAllocationMethod('manual'); }}
                              className="text-blue-600"
                            />
                            <span className="text-sm text-gray-700">
                              {t('treasury:smartPayment.manual')}
                            </span>
                          </label>
                        </>
                      )}
                    </div>

                    {/* Manual Allocation List */}
                    {excessAllocationMethod === 'manual' && openInvoices.length > 0 && (
                      <div className="mt-4 rounded-lg border border-gray-200 bg-white">
                        <table className="min-w-full divide-y divide-gray-200">
                          <thead className="bg-gray-50">
                            <tr>
                              <th className="px-4 py-2 text-start text-xs font-medium uppercase text-gray-500">
                                {t('treasury:payments.invoice')}
                              </th>
                              <th className="px-4 py-2 text-end text-xs font-medium uppercase text-gray-500">
                                {t('treasury:payments.balanceDue')}
                              </th>
                              <th className="px-4 py-2 text-end text-xs font-medium uppercase text-gray-500">
                                {t('treasury:payments.allocate')}
                              </th>
                            </tr>
                          </thead>
                          <tbody className="divide-y divide-gray-200">
                            {openInvoices.map(invoice => {
                              const allocation = manualAllocations.find(a => a.document_id === invoice.id)
                              return (
                                <tr key={invoice.id}>
                                  <td className="px-4 py-2 text-sm text-gray-900">
                                    {invoice.document_number}
                                    {invoice.days_overdue && invoice.days_overdue > 0 && (
                                      <span className="ms-2 text-xs text-red-600">
                                        ({invoice.days_overdue}d {t('common:status.overdue')})
                                      </span>
                                    )}
                                  </td>
                                  <td className="px-4 py-2 text-sm text-end text-gray-700">
                                    {formatAmount(invoice.balance_due)}
                                  </td>
                                  <td className="px-4 py-2">
                                    <Input
                                      type="number"
                                      step="0.01"
                                      min="0"
                                      max={Math.min(parseFloat(invoice.balance_due), excessAmount)}
                                      value={allocation?.amount ?? ''}
                                      onChange={e => { updateManualAllocation(invoice.id, e.target.value); }}
                                      className="w-24 text-end"
                                      placeholder="0.00"
                                    />
                                  </td>
                                </tr>
                              )
                            })}
                          </tbody>
                        </table>
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Notes */}
              <FormField label={t('treasury:payments.notes')} htmlFor="payment-notes">
                <Textarea
                  id="payment-notes"
                  rows={2}
                  value={notes}
                  onChange={e => { setNotes(e.target.value); }}
                  placeholder={t('treasury:payments.notesPlaceholder')}
                />
              </FormField>

              {/* Validation Error */}
              {validationError && (
                <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700 mt-4">
                  {validationError}
                </div>
              )}

              {/* Mutation Error */}
              {mutation.isError && (
                <div className="rounded-lg bg-red-50 p-3 text-sm text-red-700 mt-4">
                  {mutation.error instanceof Error
                    ? mutation.error.message
                    : t('common:errorMessages.generic')}
                </div>
              )}
            </ModalContent>

            <ModalFooter>
              <Button
                type="button"
                variant="secondary"
                onClick={onClose}
                disabled={mutation.isPending}
              >
                {t('common:actions.cancel')}
              </Button>
              <Button
                type="button"
                variant="primary"
                onClick={handleSubmit}
                disabled={mutation.isPending || confirmedCount === 0}
              >
                {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin me-2" />}
                {t('treasury:payments.record')}
                {confirmedCount > 0 && ` (${confirmedCount})`}
              </Button>
            </ModalFooter>
          </>
        )}
      </Modal>

      {/* Add Repository Modal */}
      <AddRepositoryModal
        isOpen={showRepositoryModal}
        onClose={() => { setShowRepositoryModal(false); }}
        onSuccess={() => {
          void queryClient.invalidateQueries({ queryKey: ['payment-repositories'] })
        }}
      />
    </>
  )
}
