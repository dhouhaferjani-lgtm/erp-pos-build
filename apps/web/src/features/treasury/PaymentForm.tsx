import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, Controller } from 'react-hook-form'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertCircle, ArrowLeft, CheckCircle2, CircleAlert, Plus, TriangleAlert } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { semanticColorTokens as colorTokens, tokens, textColors, borderColors, colors } from '../../lib/designTokens'
import { AddPartnerModal } from '../../components/organisms/AddPartnerModal/AddPartnerModal'
import { AddRepositoryModal } from '../../components/organisms/AddRepositoryModal/AddRepositoryModal'
import { Button } from '../../components/atoms/Button/Button'
import { FormField } from '../../components/atoms/FormField/FormField'
import { Input } from '../../components/atoms/Input/Input'
import { MoneyInput } from '../../components/atoms/MoneyInput/MoneyInput'
import { Radio } from '../../components/atoms/Radio/Radio'
import { Select } from '../../components/atoms/Select/Select'
import { Textarea } from '../../components/atoms/Textarea/Textarea'
import { AllocationPreview } from './components/AllocationPreview'
import { OpenInvoicesList } from './components/OpenInvoicesList'
import { AllocationMethod, type ManualAllocation, type OpenInvoice } from '../../types/treasury'
import { useWithholdingPreview } from '../withholding/hooks/useWithholding'
import type { TransactionType } from '../withholding/types'
import { useCurrency } from '../../hooks/useCurrency'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { usePaymentAllocationPreview } from './hooks/useSmartPayment'
import { bcadd, bccomp, bcdiv, bcmul, bcsub, formatCurrency as formatDecimalCurrency } from '../../lib/decimal'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import { BankPicker } from '@/components/molecules/pickers/BankPicker'
import { useBankAccountValidation } from '@/hooks/useBankAccountValidation'
import type { Bank } from '@/hooks/useBanks'
import { useCompanyConfigOptional } from '@/contexts/CompanyConfigContext'

type FeeType = 'none' | 'fixed' | 'percentage' | 'mixed'

interface PaymentMethod {
  id: string
  name: string
  is_physical: boolean
  has_maturity: boolean
  instrument_kind: 'cheque' | 'effet' | 'other' | null
  requires_third_party: boolean
  is_push: boolean
  has_deducted_fees: boolean
  is_restricted: boolean
  fee_type: FeeType | null
  fee_fixed: string
  fee_percent: string
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

interface SupplierInvoice {
  id: string
  number?: string
  document_number?: string
  partner?: Partner
  partner_id?: string
  total: string
  balance_due?: string | null
}

interface PaymentFormData {
  amount: string
  payment_method_id: string
  repository_id: string
  partner_id: string
  payment_date: string
  reference: string
  notes: string
  // Method-driven conditional fields (shown based on the selected method's flags)
  instrument_number: string
  maturity_date: string
  third_party_name: string
  drawer_name: string
  bank_id: string
  bank_name: string
  bank_branch: string
  bank_account: string
  bank_iban: string
  selected_bank: Bank | null
  bank_fallback: boolean
  withholding_enabled?: boolean
  withholding_rate?: string
  withholding_transaction_type?: string
  withholding_override_reason?: string
}

// Repository types compatible with each kind of payment method.
//  - Pure cash (is_physical && !has_maturity): cash_register / safe.
//  - Checks & drafts/traites (has_maturity): deposited toward a bank account,
//    never held in the cash drawer. Prefer bank_account; the safe is only a
//    fallback when the tenant has not configured a bank repository yet.
//  - Electronic (cards, transfers, e-wallets): bank_account / virtual.
const CASH_REPOSITORY_TYPES = ['cash_register', 'safe'] as const
const BANK_REPOSITORY_TYPES = ['bank_account', 'virtual'] as const
const CHECK_REPOSITORY_TYPES = ['bank_account'] as const
const CHECK_FALLBACK_REPOSITORY_TYPES = ['safe'] as const

/**
 * Mirror of the backend PaymentMethod::calculateFee() (bcmath, strings only).
 * Fixed fees add as-is; percentage fees compute at scale+1 (4) then round to scale.
 * Never uses parseFloat on money.
 */
function calculatePaymentMethodFee(
  method: Pick<PaymentMethod, 'fee_type' | 'fee_fixed' | 'fee_percent'>,
  amount: string,
  scale: number,
): string {
  if (!method.fee_type || method.fee_type === 'none') {
    return bcadd('0', '0', scale)
  }

  let fee = bcadd('0', '0', scale)

  if (method.fee_type === 'fixed' || method.fee_type === 'mixed') {
    fee = bcadd(fee, method.fee_fixed || '0', scale)
  }

  if (method.fee_type === 'percentage' || method.fee_type === 'mixed') {
    const percentageFee = bcdiv(bcmul(amount, method.fee_percent || '0', 4), '100', scale)
    fee = bcadd(fee, percentageFee, scale)
  }

  return fee
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
  const companyConfig = useCompanyConfigOptional()
  const countryCode = companyConfig?.config?.country_code ?? ''
  const autoDerivedIbanRef = useRef('')
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const [searchParams] = useSearchParams()
  const invoiceId = searchParams.get('invoice')
  const purchaseOrderId = searchParams.get('purchase_order')
  const deliveryNoteId = searchParams.get('delivery_note')
  const supplierInvoiceId = searchParams.get('supplier_invoice')
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
      instrument_number: '',
      maturity_date: '',
      third_party_name: '',
      drawer_name: '',
      bank_id: '',
      bank_name: '',
      bank_branch: '',
      bank_account: '',
      bank_iban: '',
      selected_bank: null,
      bank_fallback: false,
    },
  })

  // Watch partner_id and amount for smart allocation
  const selectedPartnerId = watch('partner_id')
  const paymentAmount = watch('amount')
  const selectedMethodId = watch('payment_method_id')
  const bankAccount = watch('bank_account') ?? ''
  const bankIban = watch('bank_iban') ?? ''
  const bankName = watch('bank_name') ?? ''
  const selectedBank = watch('selected_bank') ?? null
  const bankFallback = watch('bank_fallback') ?? false
  const ribValidation = useBankAccountValidation(bankAccount, countryCode, 'rib')

  useEffect(() => {
    const nextIban = ribValidation.status === 'valid' ? ribValidation.derivedIban ?? '' : ''
    if (nextIban !== '') {
      if (bankIban === '' || bankIban === autoDerivedIbanRef.current) {
        setValue('bank_iban', nextIban)
        autoDerivedIbanRef.current = nextIban
      }
    } else if (autoDerivedIbanRef.current !== '' && bankIban === autoDerivedIbanRef.current) {
      setValue('bank_iban', '')
      autoDerivedIbanRef.current = ''
    }
  }, [bankIban, ribValidation.derivedIban, ribValidation.status, setValue])

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

  // Fetch supplier invoice data if supplier invoice ID is provided
  const { data: supplierInvoiceData } = useQuery({
    queryKey: tenantScopedKey(['supplier-invoice', supplierInvoiceId]),
    queryFn: async () => {
      if (!supplierInvoiceId) return null
      const response = await api.get<{ data: SupplierInvoice }>(`/supplier-invoices/${supplierInvoiceId}`)
      return response.data.data
    },
    enabled: !!supplierInvoiceId && tenantId !== null && companyId !== null,
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
    } else if (supplierInvoiceData) {
      const supplierInvoiceNumber = supplierInvoiceData.document_number ?? supplierInvoiceData.number ?? ''
      reset({
        amount: supplierInvoiceData.balance_due || supplierInvoiceData.total,
        payment_method_id: '',
        partner_id: supplierInvoiceData.partner_id ?? supplierInvoiceData.partner?.id ?? '',
        payment_date: new Date().toISOString().split('T')[0],
        reference: supplierInvoiceNumber,
        notes: t('treasury:payments.form.paymentForSupplierInvoice', {
          invoiceNumber: supplierInvoiceNumber,
        }),
      })
    }
  }, [invoiceData, purchaseOrderData, deliveryNoteData, supplierInvoiceData, reset])

  // Fetch payment methods
  const { data: paymentMethodsData } = useQuery({
    queryKey: tenantScopedKey(['payment-methods']),
    queryFn: async () => {
      const response = await api.get<PaymentMethodsResponse>('/payment-methods')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const paymentMethods = useMemo(() => paymentMethodsData?.data ?? [], [paymentMethodsData])

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

  const repositories = useMemo(() => repositoriesData?.data ?? [], [repositoriesData])

  // Resolve the selected payment method so the form can react to its capability flags.
  const selectedMethod = useMemo(
    () => paymentMethods.find((method) => method.id === selectedMethodId) ?? null,
    [paymentMethods, selectedMethodId],
  )

  // Scope the repository options to the selected method's kind. If no repository
  // matches (e.g. the tenant has no cash repository yet) fall back to the full
  // list rather than blocking the user.
  const compatibleRepositories = useMemo(() => {
    if (!selectedMethod) return repositories

    let allowed: readonly string[]
    if (selectedMethod.has_maturity) {
      // Checks / drafts are deposited toward a bank account. Prefer
      // bank_account; fall back to the safe only when no bank repository
      // exists yet (never the cash drawer).
      const hasBankRepository = repositories.some((repo) => repo.type === 'bank_account')
      allowed = hasBankRepository ? CHECK_REPOSITORY_TYPES : CHECK_FALLBACK_REPOSITORY_TYPES
    } else if (selectedMethod.is_physical) {
      allowed = CASH_REPOSITORY_TYPES
    } else {
      allowed = BANK_REPOSITORY_TYPES
    }

    const filtered = repositories.filter((repo) => allowed.includes(repo.type))
    return filtered.length > 0 ? filtered : repositories
  }, [selectedMethod, repositories])

  // Clear the chosen repository when it is no longer compatible with the method.
  const selectedRepositoryId = watch('repository_id')
  useEffect(() => {
    if (
      selectedRepositoryId &&
      !compatibleRepositories.some((repo) => repo.id === selectedRepositoryId)
    ) {
      setValue('repository_id', '')
    }
  }, [compatibleRepositories, selectedRepositoryId, setValue])

  // Informational fee + net preview for methods that deduct a processing fee.
  const feeAmount = useMemo(() => {
    if (!selectedMethod?.has_deducted_fees || !isPositiveAmount(paymentAmount || '')) {
      return null
    }
    return calculatePaymentMethodFee(selectedMethod, paymentAmount, decimals)
  }, [selectedMethod, paymentAmount, decimals])

  const netAmount = useMemo(() => {
    if (feeAmount === null) return null
    return bcsub(paymentAmount, feeAmount, decimals)
  }, [feeAmount, paymentAmount, decimals])

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
      !supplierInvoiceId &&
      tenantId !== null &&
      companyId !== null,
  })

  const openInvoices: OpenInvoice[] = openInvoicesData ?? []
  const canAllocateOpenInvoices = Boolean(
    selectedPartnerId &&
    !invoiceId &&
    !purchaseOrderId &&
    !deliveryNoteId &&
    !supplierInvoiceId &&
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
  }, [selectedPartnerId, paymentAmount, withholdingTransactionType, currency])

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

    if (supplierInvoiceId && supplierInvoiceData) {
      return buildSingleDocumentAllocation(
        supplierInvoiceId,
        paymentAmountValue,
        supplierInvoiceData.balance_due || supplierInvoiceData.total,
      )
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

  const paymentMethodRegistration = register('payment_method_id', {
    required: t('treasury:payments.form.paymentMethodRequired'),
  })

  const createMutation = useMutation({
    mutationFn: async (data: PaymentFormData) => {
      // Prepare allocations array
      const allocations = buildPaymentAllocations(data.amount)

      // When a third party is required by an immediate method (e.g. a bank
      // transfer), fold the third-party/bank name into the payment notes — there
      // is no dedicated column and adding one is out of scope here.
      const notes =
        selectedMethod?.requires_third_party && !selectedMethod.has_maturity && data.third_party_name
          ? [data.notes, `${t('treasury:payments.form.thirdParty')}: ${data.third_party_name}`]
              .filter((part) => part && part.trim() !== '')
              .join('\n')
          : data.notes

      return apiPost<Payment>('/payments', {
        amount: data.amount,
        payment_method_id: data.payment_method_id,
        repository_id: data.repository_id,
        partner_id: data.partner_id,
        payment_date: data.payment_date,
        reference: data.reference,
        notes,
        ...(selectedMethod?.has_maturity && {
          instrument: {
            reference: data.instrument_number,
            maturity_date: data.maturity_date || undefined,
            drawer_name: data.drawer_name || undefined,
            bank_id: data.bank_id || undefined,
            bank_name: data.bank_name || undefined,
            bank_branch: data.bank_branch || undefined,
            bank_account: data.bank_account || undefined,
          },
        }),
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
          ? queryClient.invalidateQueries({ queryKey: ['invoice', invoiceId] })
          : Promise.resolve(),
        purchaseOrderId
          ? queryClient.invalidateQueries({ queryKey: ['purchase-order', purchaseOrderId] })
          : Promise.resolve(),
        deliveryNoteId
          ? queryClient.invalidateQueries({ queryKey: ['delivery-note', deliveryNoteId] })
          : Promise.resolve(),
        supplierInvoiceId
          ? queryClient.invalidateQueries({ queryKey: ['supplier-invoice', supplierInvoiceId] })
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
    } else if (supplierInvoiceId) {
      void navigate(`/purchases/supplier-invoices/${supplierInvoiceId}`)
    } else {
      void navigate('/treasury/payments')
    }
  }

  const onSubmit = (data: PaymentFormData) => {
    createMutation.mutate(data)
  }

  const backTo = invoiceId
    ? `/sales/invoices/${invoiceId}`
    : purchaseOrderId
      ? `/purchases/orders/${purchaseOrderId}`
      : deliveryNoteId
        ? `/inventory/delivery-notes/${deliveryNoteId}`
        : supplierInvoiceId
          ? `/purchases/supplier-invoices/${supplierInvoiceId}`
          : '/treasury/payments'

  const documentTitle = invoiceData
    ? t('treasury:payments.newForInvoice', { invoiceNumber: invoiceData.document_number })
    : supplierInvoiceData
      ? t('treasury:payments.newForSupplierInvoice', {
          invoiceNumber: supplierInvoiceData.document_number ?? supplierInvoiceData.number ?? '',
        })
      : t('treasury:payments.new')

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to={backTo}
          className={cn(
            'inline-flex items-center gap-2 text-sm',
            textColors.tertiary,
            textColors.hoverPrimary,
          )}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:back')}
        </Link>
        <PageHeaderTitle className={cn('text-2xl font-bold', textColors.primary)}>
          {documentTitle}
        </PageHeaderTitle>
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
                    // String-safe positivity (rule 19: no parseFloat on money) — bccomp
                    // maps empty/garbage input to 0, which fails the check (fail-closed).
                    validate: (v) => bccomp(v, '0') > 0 || t('treasury:payments.form.amountPositive'),
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
                {...paymentMethodRegistration}
                onChange={(event) => {
                  void paymentMethodRegistration.onChange(event)
                  const nextMethod = paymentMethods.find((method) => method.id === event.target.value)
                  if (nextMethod?.has_maturity) {
                    setWithholdingEnabled(false)
                    setWithholdingRate('')
                    setWithholdingTransactionType('')
                  }
                }}
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
                  {compatibleRepositories.map((repo) => (
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

            {/* Deferred-tender instrument details */}
            {selectedMethod?.has_maturity && (
              <fieldset
                className={cn('sm:col-span-2 rounded-lg border p-4', borderColors.light, colors.neutral[50])}
              >
                <legend className={cn('px-1 text-sm font-semibold', textColors.primary)}>
                  {t('treasury:instruments.formTitle')}
                </legend>
                <div className="grid gap-4 sm:grid-cols-2">
                  <FormField
                    label={t('treasury:instruments.reference')}
                    htmlFor="instrument_number"
                    required
                    error={errors.instrument_number?.message}
                  >
                    <Input
                      type="text"
                      id="instrument_number"
                      required
                      {...register('instrument_number', {
                        required: t('treasury:instruments.referenceRequired'),
                      })}
                      error={Boolean(errors.instrument_number)}
                      placeholder={t('treasury:instruments.referencePlaceholder')}
                    />
                  </FormField>

                  <FormField
                    label={t('treasury:instruments.maturityDate')}
                    htmlFor="maturity_date"
                    required={selectedMethod.instrument_kind === 'effet'}
                    error={errors.maturity_date?.message}
                  >
                    <Input
                      type="date"
                      id="maturity_date"
                      required={selectedMethod.instrument_kind === 'effet'}
                      {...register('maturity_date', {
                        required: selectedMethod.instrument_kind === 'effet'
                          ? t('treasury:instruments.maturityDateRequired')
                          : false,
                      })}
                      error={Boolean(errors.maturity_date)}
                    />
                  </FormField>

                  <FormField label={t('treasury:instruments.drawerName')} htmlFor="drawer_name">
                    <Input id="drawer_name" type="text" {...register('drawer_name')} />
                  </FormField>

                  <Input type="hidden" {...register('bank_id')} />
                  <Input type="hidden" {...register('bank_name')} />
                  <FormField label={t('treasury:instruments.bankName')} htmlFor="instrument-bank-name">
                    <BankPicker
                      id="instrument-bank-name"
                      aria-label={t('treasury:instruments.bankName')}
                      country={countryCode}
                      value={selectedBank}
                      isFallback={bankFallback}
                      fallbackValue={bankName}
                      onFallbackValueChange={(value) => {
                        setValue('bank_name', value)
                      }}
                      onFallbackChange={(isFallback) => {
                        setValue('bank_fallback', isFallback)
                        setValue('selected_bank', null)
                        setValue('bank_id', '')
                      }}
                      onChange={(bank) => {
                        setValue('selected_bank', bank)
                        setValue('bank_id', bank?.id ?? '')
                        setValue('bank_name', bank?.name ?? '')
                      }}
                    />
                  </FormField>

                  <FormField label={t('treasury:instruments.bankBranch')} htmlFor="bank_branch">
                    <Input id="bank_branch" type="text" {...register('bank_branch')} />
                  </FormField>

                  <FormField label={t('treasury:instruments.bankAccount')} htmlFor="bank_account">
                    <Input id="bank_account" type="text" {...register('bank_account')} />
                    {ribValidation.status === 'valid' ? (
                      <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.success.textStrong}`}>
                        <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
                        {t('treasury:repositories.validation.validRib')}
                      </p>
                    ) : ribValidation.status === 'invalid' && ribValidation.normalized.length >= 20 ? (
                      <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.caution.textStrong}`}>
                        <TriangleAlert className="h-3.5 w-3.5" aria-hidden />
                        {t('treasury:repositories.validation.invalidRibWarning')}
                      </p>
                    ) : ribValidation.status === 'unsupported' ? (
                      <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.info.textStrong}`}>
                        <CircleAlert className="h-3.5 w-3.5" aria-hidden />
                        {t('treasury:repositories.validation.unsupportedCountry')}
                      </p>
                    ) : null}
                  </FormField>

                  <FormField label={t('treasury:repositories.iban')} htmlFor="bank_iban">
                    <Input id="bank_iban" type="text" readOnly {...register('bank_iban')} />
                  </FormField>
                </div>
              </fieldset>
            )}

            {/* Third party / bank name (methods requiring a third party) */}
            {selectedMethod?.requires_third_party && !selectedMethod.has_maturity && (
              <FormField
                label={t('treasury:payments.form.thirdParty')}
                htmlFor="third_party_name"
                required
                error={errors.third_party_name?.message}
              >
                <Input
                  type="text"
                  id="third_party_name"
                  {...register('third_party_name', {
                    required: t('treasury:payments.form.thirdPartyRequired'),
                  })}
                  error={Boolean(errors.third_party_name)}
                  placeholder={t('treasury:payments.form.thirdPartyPlaceholder')}
                />
              </FormField>
            )}

            {/* Processing fee + net amount preview (methods that deduct a fee) */}
            {selectedMethod?.has_deducted_fees && feeAmount !== null && netAmount !== null && (
              <div className={cn('sm:col-span-2 rounded-lg border p-4', borderColors.light, colors.neutral[50])}>
                <div className="flex justify-between text-sm" data-testid="payment-fee-line">
                  <span className={textColors.tertiary}>{t('treasury:payments.form.feeDeducted')}</span>
                  <span className={cn('font-mono font-semibold', textColors.error)}>
                    - {formatDecimalCurrency(feeAmount, true, currency, decimals)}
                  </span>
                </div>
                <div
                  className={cn('mt-2 flex justify-between border-t pt-2 text-sm', borderColors.default)}
                  data-testid="payment-net-line"
                >
                  <span className={cn('font-semibold', textColors.primary)}>
                    {t('treasury:payments.form.netAmount')}
                  </span>
                  <span className={cn('font-mono font-semibold', textColors.primary)}>
                    {formatDecimalCurrency(netAmount, true, currency, decimals)}
                  </span>
                </div>
                <p className={cn('mt-2 text-xs', textColors.tertiary)}>
                  {t('treasury:payments.form.feeNote')}
                </p>
              </div>
            )}

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
                  disabled={selectedMethod?.has_maturity}
                  title={selectedMethod?.has_maturity
                    ? t('treasury:instruments.withholdingUnavailable')
                    : undefined}
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
              <div className={cn('rounded-lg border p-4', colors.white, borderColors.light)}>
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
                      <Radio
                        name="allocation-method"
                        value={method}
                        checked={allocationMethod === method}
                        onChange={() => { handleAllocationMethodChange(method) }}
                        className="mt-1"
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
