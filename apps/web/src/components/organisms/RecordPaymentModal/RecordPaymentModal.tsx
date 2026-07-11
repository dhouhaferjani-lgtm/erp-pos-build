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
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useCurrency } from '../../../hooks/useCurrency'
import { AddRepositoryModal } from '../AddRepositoryModal'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function RecordPaymentModal({
  isOpen,
  onClose,
  onSuccess,
  prefill,
}: RecordPaymentModalProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const { currency, symbol, decimals, format: formatCurrencyAmount } = useCurrency()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
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
    queryKey: tenantScopedKey(['payment-methods']),
    queryFn: async () => {
      const response = await api.get<PaymentMethodsResponse>('/payment-methods')
      return response.data
    },
    enabled: isOpen && tenantId !== null && companyId !== null,
  })

  const paymentMethods = paymentMethodsData?.data ?? []

  // Fetch repositories
  const { data: repositoriesData } = useQuery({
    queryKey: tenantScopedKey(['payment-repositories']),
    queryFn: async () => {
      const response = await api.get<RepositoriesResponse>('/payment-repositories')
      return response.data
    },
    enabled: isOpen && tenantId !== null && companyId !== null,
  })

  const repositories = repositoriesData?.data ?? []

  // Fetch open invoices for the partner (for excess allocation)
  const { data: openInvoicesData } = useQuery({
    queryKey: tenantScopedKey(['open-invoices', prefill.partner_id]),
    queryFn: async () => {
      const response = await api.get<OpenInvoicesResponse>(`/partners/${prefill.partner_id}/open-invoices`)
      return response.data
    },
    enabled: isOpen && !!prefill.partner_id && tenantId !== null && companyId !== null,
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
    onSuccess: async (response) => {
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('payments', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['invoice', prefill.document_id]) }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('invoices', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('open-invoices', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document', prefill.document_id]) }),
      ])

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
              <div className={`rounded-lg ${colorTokens.intent.success.bgSubtle} p-4 mb-4`}>
                <div className="flex items-start gap-3">
                  <CheckCircle className={`h-5 w-5 ${colorTokens.intent.success.text} flex-shrink-0 mt-0.5`} />
                  <div>
                    <h4 className={`text-sm font-medium ${colorTokens.intent.success.textStrongest}`}>
                      {t('treasury:payments.recordedSuccess')}
                    </h4>
                    <p className={`mt-1 text-sm ${colorTokens.intent.success.textStrong}`}>
                      {successData.payments.length} {t('treasury:unifiedPayment.paymentsRecorded')}
                    </p>
                  </div>
                </div>
              </div>

              <div className="space-y-4">
                <div className={`rounded-lg border ${colorTokens.border.subtle} p-4`}>
                  <h4 className={`font-medium ${colorTokens.text.primary} mb-2`}>
                    {t('treasury:payments.documentStatus')}
                  </h4>
                  <div className="flex justify-between">
                    <span className={`text-sm ${colorTokens.text.muted}`}>{successData.document.document_number}</span>
                    <span className={`text-sm font-medium ${
                      successData.document.status === 'paid' ? `${colorTokens.intent.success.text}` : `${colorTokens.intent.warning.text}`
                    }`}>
                      {successData.document.status === 'paid'
                        ? t('common:status.paid')
                        : `${t('treasury:payments.remaining')}: ${formatAmount(successData.document.balance_due)}`}
                    </span>
                  </div>
                </div>

                {parseFloat(successData.excess_handling.excess_amount) > 0 && (
                  <div className={`rounded-lg border ${colorTokens.intent.warning.borderSubtle} ${colorTokens.intent.warning.bgSubtle} p-4`}>
                    <h4 className={`font-medium ${colorTokens.intent.warning.textStrongest} mb-2`}>
                      {t('treasury:unifiedPayment.excessHandled')}
                    </h4>
                    <p className={`text-sm ${colorTokens.intent.warning.textStrong}`}>
                      {formatAmount(successData.excess_handling.excess_amount)} - {
                        successData.excess_handling.allocation_method === 'advance'
                          ? t('treasury:unifiedPayment.keptAsAdvance')
                          : t('treasury:unifiedPayment.allocatedToInvoices')
                      }
                    </p>
                    {successData.excess_handling.allocations.length > 0 && (
                      <ul className={`mt-2 text-sm ${colorTokens.intent.warning.textStrong}`}>
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
              <div className={`rounded-lg ${colorTokens.intent.primary.bgSubtle} p-4 mb-4`}>
                <div className={`flex items-center gap-2 text-sm ${colorTokens.intent.primary.textStronger}`}>
                  <span className="font-medium">{t('treasury:payments.payingFor')}:</span>
                  <span>{prefill.reference}</span>
                  <span className={`${colorTokens.intent.primary.text}`}>({prefill.partner_name})</span>
                </div>
              </div>

              {/* Balance Summary */}
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.page} p-4 mb-4`}>
                <div className="grid grid-cols-3 gap-4 text-center">
                  <div>
                    <span className={`text-sm font-medium ${colorTokens.text.subtle} block`}>
                      {t('treasury:unifiedPayment.balanceDue')}
                    </span>
                    <span className={`text-xl font-bold ${colorTokens.text.primary}`}>
                      {formatAmount(balanceDue)}
                    </span>
                  </div>
                  <div>
                    <span className={`text-sm font-medium ${colorTokens.text.subtle} block`}>
                      {t('treasury:unifiedPayment.totalEntered')}
                    </span>
                    <span className={`text-xl font-bold ${colorTokens.intent.primary.text}`}>
                      {formatAmount(totalEntered)}
                    </span>
                  </div>
                  <div>
                    <span className={`text-sm font-medium ${colorTokens.text.subtle} block`}>
                      {t('treasury:unifiedPayment.remaining')}
                    </span>
                    <span className={`text-xl font-bold ${
                      Math.abs(remaining) < 0.01 ? `${colorTokens.intent.success.text}` :
                      remaining > 0 ? `${colorTokens.intent.warning.text}` : `${colorTokens.intent.danger.text}`
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
                <h4 className={`text-sm font-medium ${colorTokens.text.secondary}`}>
                  {t('treasury:unifiedPayment.paymentLines')} ({confirmedCount} {t('treasury:unifiedPayment.confirmed')})
                </h4>

                {paymentLines.map((line, index) => (
                  <div
                    key={line.id}
                    className={`rounded-lg border p-4 ${
                      line.confirmed ? `${colorTokens.intent.success.border} ${colorTokens.intent.success.bgSubtle}` : `${colorTokens.border.subtle} ${colorTokens.surface.base}`
                    }`}
                  >
                    <div className="flex items-center justify-between mb-4">
                      <h5 className={`text-sm font-medium ${colorTokens.text.secondary}`}>
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
                          <span className={`inline-flex items-center gap-1 text-sm font-medium ${colorTokens.intent.success.textStrong}`}>
                            <CheckCircle className="h-4 w-4" />
                            {t('treasury:unifiedPayment.lineConfirmed')}
                          </span>
                        )}
                        {!line.confirmed && paymentLines.length > 1 && (
                          <button
                            type="button"
                            onClick={() => { removePaymentLine(line.id); }}
                            className={`${colorTokens.intent.danger.text} ${colorTokens.variants.hoverTextRed800} p-1`}
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
                              <span className={`absolute start-3 top-1/2 -translate-y-1/2 ${colorTokens.text.subtle}`}>
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
                                onClick={() => { updatePaymentLine(line.id, 'amount', remaining.toFixed(decimals)); }}
                                className={`text-xs ${colorTokens.intent.primary.text} ${colorTokens.variants.hoverTextBlue800} font-medium`}
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
                  className={`inline-flex items-center gap-2 text-sm font-medium ${colorTokens.intent.primary.text} ${colorTokens.variants.hoverTextBlue800}`}
                >
                  <Plus className="h-4 w-4" />
                  {t('treasury:unifiedPayment.addPaymentLine')}
                </button>
              </div>

              {/* Excess Allocation Panel */}
              {excessAmount > 0 && (
                <div className={`rounded-lg border ${colorTokens.intent.warning.borderSubtle} ${colorTokens.intent.warning.bgSubtle} p-4 mb-4`}>
                  <h4 className={`font-medium ${colorTokens.intent.warning.textStrongest} mb-3`}>
                    {t('treasury:unifiedPayment.excessPayment', { amount: formatAmount(excessAmount) })}
                  </h4>

                  <div className="space-y-3">
                    <p className={`text-sm ${colorTokens.intent.warning.textStronger}`}>
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
                          className={`${colorTokens.intent.primary.text}`}
                        />
                        <span className={`text-sm ${colorTokens.text.secondary}`}>
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
                              className={`${colorTokens.intent.primary.text}`}
                            />
                            <span className={`text-sm ${colorTokens.text.secondary}`}>
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
                              className={`${colorTokens.intent.primary.text}`}
                            />
                            <span className={`text-sm ${colorTokens.text.secondary}`}>
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
                              className={`${colorTokens.intent.primary.text}`}
                            />
                            <span className={`text-sm ${colorTokens.text.secondary}`}>
                              {t('treasury:smartPayment.manual')}
                            </span>
                          </label>
                        </>
                      )}
                    </div>

                    {/* Manual Allocation List */}
                    {excessAllocationMethod === 'manual' && openInvoices.length > 0 && (
                      <div className={`mt-4 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base}`}>
                        <table className={`min-w-full divide-y ${colorTokens.border.divider}`}>
                          <thead className={`${colorTokens.surface.page}`}>
                            <tr>
                              <th className={`px-4 py-2 text-start text-xs font-medium uppercase ${colorTokens.text.subtle}`}>
                                {t('treasury:payments.invoice')}
                              </th>
                              <th className={`px-4 py-2 text-end text-xs font-medium uppercase ${colorTokens.text.subtle}`}>
                                {t('treasury:payments.balanceDue')}
                              </th>
                              <th className={`px-4 py-2 text-end text-xs font-medium uppercase ${colorTokens.text.subtle}`}>
                                {t('treasury:payments.allocate')}
                              </th>
                            </tr>
                          </thead>
                          <tbody className={`divide-y ${colorTokens.border.divider}`}>
                            {openInvoices.map(invoice => {
                              const allocation = manualAllocations.find(a => a.document_id === invoice.id)
                              return (
                                <tr key={invoice.id}>
                                  <td className={`px-4 py-2 text-sm ${colorTokens.text.primary}`}>
                                    {invoice.document_number}
                                    {invoice.days_overdue && invoice.days_overdue > 0 && (
                                      <span className={`ms-2 text-xs ${colorTokens.intent.danger.text}`}>
                                        ({invoice.days_overdue}d {t('common:status.overdue')})
                                      </span>
                                    )}
                                  </td>
                                  <td className={`px-4 py-2 text-sm text-end ${colorTokens.text.secondary}`}>
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
                <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-3 text-sm ${colorTokens.intent.danger.textStrong} mt-4`}>
                  {validationError}
                </div>
              )}

              {/* Mutation Error */}
              {mutation.isError && (
                <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-3 text-sm ${colorTokens.intent.danger.textStrong} mt-4`}>
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
        onSuccess={async () => {
          await queryClient.invalidateQueries({
            predicate: scopedNamespacePredicate('payment-repositories', tenantId, companyId),
          })
        }}
      />
    </>
  )
}
