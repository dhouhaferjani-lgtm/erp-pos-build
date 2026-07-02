import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, Controller } from 'react-hook-form'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Plus, AlertCircle } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors, colors } from '../../lib/designTokens'
import { AddPartnerModal, AddRepositoryModal } from '../../components/organisms'
import { Button, FormField, Input, MoneyInput, Select, Textarea } from '../../components/atoms'
import { AllocationPreview, OpenInvoicesList } from './components'
import { AllocationMethod, type ManualAllocation, type OpenInvoice } from '../../types/treasury'
import { useWithholdingPreview } from '../withholding'
import type { TransactionType } from '../withholding/types'
import { useCurrency } from '../../hooks/useCurrency'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { usePaymentAllocationPreview } from './hooks/useSmartPayment'
import { bccomp, bcdiv, bcmul, bcsub } from '../../lib/decimal'

interface PaymentMethod {
  id: string
  name: string
  is_physical: boolean
}

interface Partner {
  id: string
  name: string
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

interface PartnersResponse {
  data: Partner[]
}

interface RepositoriesResponse {
  data: Repository[]
}

interface Payment {
  id: string
  payment_number: string
  amount: number
}

interface Invoice {
  id: string
  document_number: string
  partner_id: string
  partner_name: string
  total: string
  subtotal: string
  tax_amount: string
  amount_paid?: number
  amount_residual?: number
}

interface PaymentFormData {
  amount: string
  payment_method_id: string
  repository_id: string
  partner_id: string
  payment_date: string
  reference: string
  notes: string
  withholding_enabled?: boolean
  withholding_rate?: string
  withholding_transaction_type?: string
  withholding_override_reason?: string
}

interface PaymentAllocationPayload {
  document_id: string
  amount: string
}

function isPositiveAmount(value: string): boolean {
  return value.trim() !== '' && bccomp(value, '0') > 0
}

function normalizeManualAllocations(allocations: ManualAllocation[]): PaymentAllocationPayload[] {
  const normalizedAllocations: PaymentAllocationPayload[] = []

  for (const allocation of allocations) {
    if (isPositiveAmount(allocation.amount)) {
      normalizedAllocations.push({
        document_id: allocation.document_id,
        amount: allocation.amount,
      })
    }
  }

  return normalizedAllocations
}

function buildAutomaticAllocations(
  invoices: OpenInvoice[],
  paymentAmountValue: string,
  method: AllocationMethod,
  decimals: number,
): PaymentAllocationPayload[] {
  let remainingAmount = paymentAmountValue || '0'
  const sortedInvoices = [...invoices].sort((a, b) => {
    const firstDate = method === AllocationMethod.DUE_DATE ? a.due_date : a.document_date
    const secondDate = method === AllocationMethod.DUE_DATE ? b.due_date : b.document_date
    return new Date(firstDate).getTime() - new Date(secondDate).getTime()
  })
  const allocations: PaymentAllocationPayload[] = []

  for (const invoice of sortedInvoices) {
    if (bccomp(remainingAmount, '0') <= 0) break

    const balanceDue = invoice.balance_due || '0'
    if (bccomp(balanceDue, '0') <= 0) continue

    const allocationAmount = bccomp(remainingAmount, balanceDue) > 0
      ? balanceDue
      : remainingAmount

    if (isPositiveAmount(allocationAmount)) {
      allocations.push({
        document_id: invoice.id,
        amount: allocationAmount,
      })
      remainingAmount = bcsub(remainingAmount, allocationAmount, decimals)
    }
  }

  return allocations
}

function buildSingleDocumentAllocation(
  documentId: string,
  paymentAmountValue: string,
  balanceDueValue: string,
): PaymentAllocationPayload[] {
  if (!isPositiveAmount(paymentAmountValue) || !isPositiveAmount(balanceDueValue)) {
    return []
  }

  const allocationAmount = bccomp(paymentAmountValue, balanceDueValue) > 0
    ? balanceDueValue
    : paymentAmountValue

  return isPositiveAmount(allocationAmount)
    ? [{ document_id: documentId, amount: allocationAmount }]
    : []
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

export function PaymentForm() {
  const { t } = useTranslation(['treasury', 'common', 'sales', 'withholding'])
  const { currency, symbol, decimals, format: formatCurrency } = useCurrency()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [searchParams] = useSearchParams()
  const invoiceId = searchParams.get('invoice')
  const purchaseOrderId = searchParams.get('purchase_order')
  const deliveryNoteId = searchParams.get('delivery_note')
  const [showPartnerModal, setShowPartnerModal] = useState(false)
  const [showRepositoryModal, setShowRepositoryModal] = useState(false)
  const [withholdingEnabled, setWithholdingEnabled] = useState(false)
  const [withholdingTransactionType, setWithholdingTransactionType] = useState<TransactionType | ''>('')
  const [withholdingRate, setWithholdingRate] = useState('')
  const [allocationMethod, setAllocationMethod] = useState<AllocationMethod>(AllocationMethod.FIFO)
  const [manualAllocations, setManualAllocations] = useState<ManualAllocation[]>([])

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    control,
    formState: { errors, isSubmitting },
  } = useForm<PaymentFormData>({
    defaultValues: {
      amount: '',
      payment_method_id: '',
      repository_id: '',
      partner_id: '',
      payment_date: new Date().toISOString().split('T')[0],
      reference: '',
      notes: '',
    },
  })

  // Watch partner_id and amount for smart allocation
  const selectedPartnerId = watch('partner_id')
  const paymentAmount = watch('amount')

  // Fetch invoice data if invoice ID is provided in query params
  const { data: invoiceData } = useQuery({
    queryKey: tenantScopedKey(['invoice', invoiceId]),
    queryFn: async () => {
      if (!invoiceId) return null
      const response = await api.get<{ data: Invoice }>(`/invoices/${invoiceId}`)
      return response.data.data
    },
    enabled: !!invoiceId && tenantId !== null && companyId !== null,
  })

  // Fetch purchase order data if purchase order ID is provided
  const { data: purchaseOrderData } = useQuery({
    queryKey: tenantScopedKey(['purchase-order', purchaseOrderId]),
    queryFn: async () => {
      if (!purchaseOrderId) return null
      const response = await api.get<{ data: Invoice }>(`/purchase-orders/${purchaseOrderId}`)
      return response.data.data
    },
    enabled: !!purchaseOrderId && tenantId !== null && companyId !== null,
  })

  // Fetch delivery note data if delivery note ID is provided
  const { data: deliveryNoteData } = useQuery({
    queryKey: tenantScopedKey(['delivery-note', deliveryNoteId]),
    queryFn: async () => {
      if (!deliveryNoteId) return null
      const response = await api.get<{ data: Invoice }>(`/documents/${deliveryNoteId}`)
      return response.data.data
    },
    enabled: !!deliveryNoteId && tenantId !== null && companyId !== null,
  })

  // Pre-fill form when document data is loaded
  useEffect(() => {
    if (invoiceData) {
      const amountResidual = invoiceData.amount_residual == null
        ? invoiceData.total
        : invoiceData.amount_residual.toString()
      reset({
        amount: amountResidual,
        payment_method_id: '',
        partner_id: invoiceData.partner_id,
        payment_date: new Date().toISOString().split('T')[0],
        reference: invoiceData.document_number,
        notes: t('treasury:payments.form.paymentForInvoice', { invoiceNumber: invoiceData.document_number }),
      })
    } else if (purchaseOrderData) {
      reset({
        amount: purchaseOrderData.total,
        payment_method_id: '',
        partner_id: purchaseOrderData.partner_id,
        payment_date: new Date().toISOString().split('T')[0],
        reference: purchaseOrderData.document_number,
        notes: t('treasury:payments.form.paymentForPurchaseOrder', {
          defaultValue: 'Payment for Purchase Order {{poNumber}}',
          poNumber: purchaseOrderData.document_number
        }),
      })
    } else if (deliveryNoteData) {
      reset({
        amount: deliveryNoteData.total || '',
        payment_method_id: '',
        partner_id: deliveryNoteData.partner_id,
        payment_date: new Date().toISOString().split('T')[0],
        reference: deliveryNoteData.document_number,
        notes: t('treasury:payments.form.paymentForDeliveryNote', {
          defaultValue: 'Payment for Delivery Note {{dnNumber}}',
          dnNumber: deliveryNoteData.document_number
        }),
      })
    }
  }, [invoiceData, purchaseOrderData, deliveryNoteData, reset, t])

  // Fetch payment methods
  const { data: paymentMethodsData } = useQuery({
    queryKey: tenantScopedKey(['payment-methods']),
    queryFn: async () => {
      const response = await api.get<PaymentMethodsResponse>('/payment-methods')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const paymentMethods = paymentMethodsData?.data ?? []

  // Fetch partners
  const { data: partnersData } = useQuery({
    queryKey: tenantScopedKey(['partners']),
    queryFn: async () => {
      const response = await api.get<PartnersResponse>('/partners')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const partners = partnersData?.data ?? []

  // Fetch repositories
  const { data: repositoriesData } = useQuery({
    queryKey: tenantScopedKey(['payment-repositories']),
    queryFn: async () => {
      const response = await api.get<RepositoriesResponse>('/payment-repositories')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const repositories = repositoriesData?.data ?? []

  // Fetch open invoices for selected partner (for smart allocation)
  const { data: openInvoicesData } = useQuery({
    queryKey: tenantScopedKey(['open-invoices', selectedPartnerId]),
    queryFn: async () => {
      if (!selectedPartnerId) return null
      const response = await api.get<{ data: OpenInvoice[] }>(
        `/partners/${selectedPartnerId}/open-invoices`
      )
      return response.data.data
    },
    enabled: !!selectedPartnerId &&
      !invoiceId &&
      !purchaseOrderId &&
      !deliveryNoteId &&
      tenantId !== null &&
      companyId !== null,
  })

  const openInvoices: OpenInvoice[] = openInvoicesData ?? []
  const canAllocateOpenInvoices = Boolean(
    selectedPartnerId &&
    !invoiceId &&
    !purchaseOrderId &&
    !deliveryNoteId &&
    openInvoices.length > 0
  )

  // Withholding preview - check if withholding should be applied
  const withholdingPreviewMutation = useWithholdingPreview()
  const allocationPreviewMutation = usePaymentAllocationPreview()

  useEffect(() => {
    if (selectedPartnerId && paymentAmount && bccomp(paymentAmount, '0') > 0) {
      withholdingPreviewMutation.mutate({
        partner_id: selectedPartnerId,
        amount: paymentAmount,
        currency,
        transaction_type: withholdingTransactionType || undefined,
      })
    }
  }, [selectedPartnerId, paymentAmount, withholdingTransactionType])

  const withholdingPreview = withholdingPreviewMutation.data
  const paymentAmountValue = paymentAmount || '0'
  const withholdingAmount = withholdingRate
    ? bcdiv(bcmul(paymentAmountValue, withholdingRate, decimals + 2), '100', decimals)
    : '0'
  const netPaymentAmount = bcsub(paymentAmountValue, withholdingAmount, decimals)

  // Update withholding rate when preview changes
  useEffect(() => {
    if (withholdingPreview?.calculation && !withholdingRate) {
      setWithholdingRate(withholdingPreview.calculation.rate_percentage.toString())
    }
  }, [withholdingPreview])

  const buildPaymentAllocations = (paymentAmountValue: string): PaymentAllocationPayload[] => {
    if (invoiceId && invoiceData) {
      const amountResidual = invoiceData.amount_residual == null
        ? invoiceData.total
        : String(invoiceData.amount_residual)

      return buildSingleDocumentAllocation(invoiceId, paymentAmountValue, amountResidual)
    }

    if (!canAllocateOpenInvoices || !isPositiveAmount(paymentAmountValue)) {
      return []
    }

    if (allocationMethod === AllocationMethod.MANUAL) {
      return normalizeManualAllocations(manualAllocations)
    }

    return buildAutomaticAllocations(openInvoices, paymentAmountValue, allocationMethod, decimals)
  }

  const handleAllocationMethodChange = (method: AllocationMethod) => {
    setAllocationMethod(method)
    setManualAllocations([])
    allocationPreviewMutation.reset()
  }

  const handlePreviewAllocation = () => {
    const allocations = buildPaymentAllocations(paymentAmount)

    allocationPreviewMutation.mutate({
      partner_id: selectedPartnerId,
      payment_amount: paymentAmount,
      allocation_method: allocationMethod,
      ...(allocationMethod === AllocationMethod.MANUAL && { manual_allocations: allocations }),
    })
  }

  const methodOptions: readonly {
    method: AllocationMethod
    label: string
    description: string
  }[] = [
    {
      method: AllocationMethod.FIFO,
      label: t('treasury:smartPayment.allocation.fifo'),
      description: t('treasury:smartPayment.allocation.fifoDescription'),
    },
    {
      method: AllocationMethod.DUE_DATE,
      label: t('treasury:smartPayment.allocation.dueDate'),
      description: t('treasury:smartPayment.allocation.dueDateDescription'),
    },
    {
      method: AllocationMethod.MANUAL,
      label: t('treasury:smartPayment.allocation.manual'),
      description: t('treasury:smartPayment.allocation.manualDescription'),
    },
  ]

  const allocationPreviewDisabled =
    allocationPreviewMutation.isPending ||
    !canAllocateOpenInvoices ||
    !isPositiveAmount(paymentAmount) ||
    (allocationMethod === AllocationMethod.MANUAL && normalizeManualAllocations(manualAllocations).length === 0)

  const createMutation = useMutation({
    mutationFn: (data: PaymentFormData) => {
      // Prepare allocations array
      const allocations = buildPaymentAllocations(data.amount)

      return apiPost<Payment>('/payments', {
        ...data,
        amount: data.amount,
        allocations: allocations.length > 0 ? allocations : undefined,
        withholding_enabled: withholdingEnabled,
        withholding_rate: withholdingEnabled && withholdingRate ? withholdingRate : undefined,
        withholding_transaction_type: withholdingEnabled && withholdingTransactionType ? withholdingTransactionType : undefined,
        withholding_override_reason: data.withholding_override_reason,
      })
    },
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ predicate: scopedNamespacePredicate('payments', tenantId, companyId) }),
        invoiceId
          ? queryClient.invalidateQueries({ queryKey: tenantScopedKey(['invoice', invoiceId]) })
          : Promise.resolve(),
        purchaseOrderId
          ? queryClient.invalidateQueries({ queryKey: tenantScopedKey(['purchase-order', purchaseOrderId]) })
          : Promise.resolve(),
        deliveryNoteId
          ? queryClient.invalidateQueries({ queryKey: tenantScopedKey(['delivery-note', deliveryNoteId]) })
          : Promise.resolve(),
        queryClient.invalidateQueries({ predicate: scopedNamespacePredicate('invoices', tenantId, companyId) }),
        queryClient.invalidateQueries({ predicate: scopedNamespacePredicate('open-invoices', tenantId, companyId) }),
      ])
      toast.success(t('treasury:payments.messages.created'))

      handleNavigateAway()
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Navigate away after successful payment creation (and optional allocation)
  const handleNavigateAway = () => {
    if (invoiceId) {
      void navigate(`/sales/invoices/${invoiceId}`)
    } else if (purchaseOrderId) {
      void navigate(`/purchases/orders/${purchaseOrderId}`)
    } else if (deliveryNoteId) {
      void navigate(`/inventory/delivery-notes/${deliveryNoteId}`)
    } else {
      void navigate('/treasury/payments')
    }
  }

  const onSubmit = (data: PaymentFormData) => {
    createMutation.mutate(data)
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to={invoiceId ? `/sales/invoices/${invoiceId}` : '/treasury/payments'}
          className={cn(
            'inline-flex items-center gap-2 text-sm',
            textColors.tertiary,
            textColors.hoverPrimary,
          )}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:back')}
        </Link>
        <h1 className={cn('text-2xl font-bold', textColors.primary)}>
          {invoiceData
            ? t('treasury:payments.newForInvoice', { invoiceNumber: invoiceData.document_number })
            : t('treasury:payments.new')}
        </h1>
      </div>

      {/* Form */}
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        <div className={tokens.card.base}>
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Amount */}
            <FormField
              label={t('treasury:payments.form.amount')}
              htmlFor="amount"
              required
              error={errors.amount?.message}
            >
              <div className="relative">
                <span className={cn('absolute start-3 top-1/2 -translate-y-1/2 z-10', textColors.disabled)}>
                  {symbol}
                </span>
                <Controller
                  name="amount"
                  control={control}
                  rules={{
                    required: t('treasury:payments.form.amountRequired'),
                    validate: (v) => parseFloat(v) > 0 || t('treasury:payments.form.amountPositive'),
                  }}
                  render={({ field }) => (
                    <MoneyInput
                      id="amount"
                      currency={currency}
                      min="0.01"
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      onBlur={field.onBlur}
                      ref={field.ref}
                      error={!!errors.amount}
                      className="ps-10 pe-3"
                    />
                  )}
                />
              </div>
            </FormField>

            {/* Payment Method */}
            <FormField
              label={t('treasury:payments.form.paymentMethod')}
              htmlFor="payment_method_id"
              required
              error={errors.payment_method_id?.message}
            >
              <Select
                id="payment_method_id"
                {...register('payment_method_id', {
                  required: t('treasury:payments.form.paymentMethodRequired'),
                })}
                error={Boolean(errors.payment_method_id)}
              >
                <option value="">{t('treasury:payments.form.selectMethod')}</option>
                {paymentMethods.map((method) => (
                  <option key={method.id} value={method.id}>
                    {method.name}
                  </option>
                ))}
              </Select>
            </FormField>

            {/* Repository */}
            <FormField
              label={t('treasury:payments.form.repository')}
              htmlFor="repository_id"
              required
              error={errors.repository_id?.message}
            >
              <div className="flex gap-2">
                <Select
                  id="repository_id"
                  {...register('repository_id', { required: t('treasury:payments.form.repositoryRequired') })}
                  error={Boolean(errors.repository_id)}
                >
                  <option value="">{t('treasury:payments.form.selectRepository')}</option>
                  {repositories.map((repo) => (
                    <option key={repo.id} value={repo.id}>
                      {repo.name} ({repo.code})
                    </option>
                  ))}
                </Select>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => { setShowRepositoryModal(true) }}
                  title={t('treasury:payments.form.addRepository')}
                  className="mt-1 shrink-0"
                >
                  <Plus className="h-4 w-4" />
                </Button>
              </div>
            </FormField>

            {/* Partner */}
            <FormField
              label={t('treasury:payments.partner')}
              htmlFor="partner_id"
              required
              error={errors.partner_id?.message}
            >
              <div className="flex gap-2">
                <Select
                  id="partner_id"
                  {...register('partner_id', { required: t('treasury:payments.form.partnerRequired') })}
                  error={Boolean(errors.partner_id)}
                >
                  <option value="">{t('treasury:payments.form.selectPartner')}</option>
                  {partners.map((partner) => (
                    <option key={partner.id} value={partner.id}>
                      {partner.name}
                    </option>
                  ))}
                </Select>
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => { setShowPartnerModal(true) }}
                  title={t('treasury:payments.form.addPartner')}
                  className="mt-1 shrink-0"
                >
                  <Plus className="h-4 w-4" />
                </Button>
              </div>
            </FormField>

            {/* Payment Date */}
            <FormField
              label={t('treasury:payments.form.paymentDate')}
              htmlFor="payment_date"
              required
              error={errors.payment_date?.message}
            >
              <Input
                type="date"
                id="payment_date"
                {...register('payment_date', {
                  required: t('treasury:payments.form.paymentDateRequired'),
                })}
                error={Boolean(errors.payment_date)}
              />
            </FormField>

            {/* Reference */}
            <FormField label={t('treasury:payments.reference')} htmlFor="reference">
              <Input
                type="text"
                id="reference"
                {...register('reference')}
                placeholder={t('treasury:payments.form.referencePlaceholder')}
              />
            </FormField>

            {/* Notes */}
            <FormField
              className="sm:col-span-2"
              label={t('treasury:payments.notes')}
              htmlFor="notes"
            >
              <Textarea
                id="notes"
                rows={3}
                {...register('notes')}
                placeholder={t('treasury:payments.form.notesPlaceholder')}
              />
            </FormField>
          </div>
        </div>

        {/* Withholding Tax Section */}
        {withholdingPreview?.should_withhold && !withholdingEnabled && (
          <div className={cn(tokens.alert.base, tokens.alert.info)}>
            <div className="flex items-start gap-3">
              <AlertCircle className={cn('h-5 w-5 mt-0.5', textColors.brand)} />
              <div className="flex-1">
                <p className={cn('text-sm', textColors.brand)}>
                  {t('withholding:form.recommendedAlert')}
                </p>
                <Button
                  type="button"
                  variant="primary"
                  size="sm"
                  onClick={() => { setWithholdingEnabled(true) }}
                  className="mt-2"
                >
                  {t('withholding:form.enable')}
                </Button>
              </div>
            </div>
          </div>
        )}

        {withholdingEnabled && (
          <div className={tokens.card.base}>
            <div className="mb-4">
              <h3 className={cn(tokens.heading.section, 'mb-4')}>
                {t('withholding:title')}
              </h3>
              <button
                type="button"
                onClick={() => {
                  setWithholdingEnabled(false)
                  setWithholdingRate('')
                  setWithholdingTransactionType('')
                }}
                className={cn('mt-1 text-sm', textColors.tertiary, textColors.hoverPrimary)}
              >
                {t('common:disable')}
              </button>
            </div>

            <div className="grid gap-6 sm:grid-cols-2">
              {/* Transaction Type */}
              <FormField
                label={t('withholding:form.transactionType')}
                htmlFor="transaction_type"
              >
                <Select
                  id="transaction_type"
                  value={withholdingTransactionType}
                  onChange={(e) => { setWithholdingTransactionType(e.target.value as TransactionType) }}
                >
                  <option value="">{t('common:select')}</option>
                  <option value="services">{t('withholding:transactionTypes.services')}</option>
                  <option value="goods">{t('withholding:transactionTypes.goods')}</option>
                  <option value="rental">{t('withholding:transactionTypes.rental')}</option>
                  <option value="rental_hotel">{t('withholding:transactionTypes.rental_hotel')}</option>
                  <option value="commission">{t('withholding:transactionTypes.commission')}</option>
                  <option value="export_services">{t('withholding:transactionTypes.export_services')}</option>
                </Select>
              </FormField>

              {/* Withholding Rate */}
              <FormField
                label={t('withholding:form.rate')}
                htmlFor="withholding_rate"
                helperText={
                  withholdingPreview?.calculation
                    ? t('withholding:preview.suggestedRate', { rate: withholdingPreview.calculation.rate_percentage })
                    : undefined
                }
              >
                <div className="relative">
                  <Input
                    type="number"
                    step="0.01"
                    id="withholding_rate"
                    value={withholdingRate}
                    onChange={(e) => { setWithholdingRate(e.target.value) }}
                    className="pe-8 ps-3"
                    placeholder="0.00"
                  />
                  <span className={cn('absolute end-3 top-1/2 -translate-y-1/2', textColors.disabled)}>
                    %
                  </span>
                </div>
              </FormField>

              {/* Override Reason (if rate differs from suggested) */}
              {withholdingRate &&
                withholdingPreview?.calculation &&
                bccomp(withholdingRate, withholdingPreview.calculation.rate_percentage.toString()) !== 0 && (
                  <FormField
                    className="sm:col-span-2"
                    label={t('withholding:form.overrideReason')}
                    htmlFor="withholding_override_reason"
                    required
                    error={errors.withholding_override_reason?.message}
                  >
                    <Textarea
                      id="withholding_override_reason"
                      rows={2}
                      {...register('withholding_override_reason', {
                        required: t('withholding:form.overrideReasonRequired'),
                      })}
                      error={Boolean(errors.withholding_override_reason)}
                      placeholder={t('withholding:form.overrideReason')}
                    />
                  </FormField>
                )}
            </div>

            {/* Calculation Preview */}
            {withholdingPreview?.calculation && withholdingRate && (
              <div className={cn('mt-6 rounded-lg border p-4', borderColors.light, colors.neutral[50])}>
                <h4 className={cn('mb-3 text-sm font-semibold', textColors.primary)}>
                  {t('withholding:form.calculation')}
                </h4>
                <div className="space-y-2">
                  <div className="flex justify-between text-sm">
                    <span className={textColors.tertiary}>{t('withholding:form.grossAmount')}</span>
                    <span className={cn('font-mono font-semibold', textColors.primary)}>
                      {formatCurrency(paymentAmountValue)}
                    </span>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className={textColors.tertiary}>{t('withholding:form.withholdingAmount')}</span>
                    <span className={cn('font-mono font-semibold', textColors.error)}>
                      - {formatCurrency(withholdingAmount)}
                    </span>
                  </div>
                  <div className={cn('flex justify-between border-t pt-2 text-sm', borderColors.default)}>
                    <span className={cn('font-semibold', textColors.primary)}>{t('withholding:form.netPayment')}</span>
                    <span className={cn('font-mono text-lg font-bold', textColors.primary)}>
                      {formatCurrency(netPaymentAmount)}
                    </span>
                  </div>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Smart Payment Allocation Section */}
        {canAllocateOpenInvoices && (
          <div className={tokens.card.base}>
            <div className="mb-4">
              <h3 className={cn(tokens.heading.section, 'mb-1')}>
                {t('treasury:smartPayment.allocation.title')}
              </h3>
              <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                {t('treasury:smartPayment.allocation.description')}
              </p>
            </div>

            <div className="space-y-6">
              <div className={cn('rounded-lg border bg-white p-4', borderColors.light)}>
                <h4 className={cn('mb-4 text-sm font-medium', textColors.primary)}>
                  {t('treasury:smartPayment.allocation.method')}
                </h4>
                <div className="space-y-3">
                  {methodOptions.map(({ method, label, description }) => (
                    <label
                      key={method}
                      className={cn(
                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                        borderColors.light,
                        colors.hover.gray50
                      )}
                    >
                      <input
                        type="radio"
                        name="allocation-method"
                        value={method}
                        checked={allocationMethod === method}
                        onChange={() => { handleAllocationMethodChange(method) }}
                        className={cn('mt-1', tokens.radio.base)}
                      />
                      <span className="flex-1">
                        <span className={cn('block font-medium', textColors.primary)}>{label}</span>
                        <span className={cn('block text-sm', textColors.tertiary)}>{description}</span>
                      </span>
                    </label>
                  ))}
                </div>
              </div>

              <OpenInvoicesList
                partnerId={selectedPartnerId}
                invoices={openInvoices}
                allocationMethod={allocationMethod}
                selectedAllocations={manualAllocations}
                onAllocationChange={setManualAllocations}
              />

              {allocationPreviewMutation.data && (
                <AllocationPreview
                  preview={allocationPreviewMutation.data}
                  isLoading={allocationPreviewMutation.isPending}
                />
              )}

              <div className="flex justify-end">
                <Button
                  type="button"
                  variant="secondary"
                  onClick={handlePreviewAllocation}
                  disabled={allocationPreviewDisabled}
                >
                  {allocationPreviewMutation.isPending
                    ? t('common:status.loading')
                    : t('treasury:smartPayment.allocation.previewButton')}
                </Button>
              </div>
            </div>
          </div>
        )}

        {/* Form Actions */}
        <div className="flex items-center justify-end gap-4">
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              void navigate(invoiceId ? `/sales/invoices/${invoiceId}` : '/treasury/payments')
            }}
          >
            {t('common:cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={isSubmitting}>
            {isSubmitting ? t('common:saving') : t('common:save')}
          </Button>
        </div>
      </form>

      {/* Add Partner Modal */}
      <AddPartnerModal
        isOpen={showPartnerModal}
        onClose={() => { setShowPartnerModal(false) }}
        onSuccess={(partner) => {
          setValue('partner_id', partner.id)
          void queryClient.invalidateQueries({
            predicate: scopedNamespacePredicate('partners', tenantId, companyId),
          })
        }}
      />

      {/* Add Repository Modal */}
      <AddRepositoryModal
        isOpen={showRepositoryModal}
        onClose={() => { setShowRepositoryModal(false) }}
        onSuccess={(repository) => {
          setValue('repository_id', repository.id)
          void queryClient.invalidateQueries({
            predicate: scopedNamespacePredicate('payment-repositories', tenantId, companyId),
          })
        }}
      />
    </div>
  )
}
