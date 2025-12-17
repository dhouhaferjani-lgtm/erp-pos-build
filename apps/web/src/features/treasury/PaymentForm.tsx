import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Plus } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, getErrorMessage } from '../../lib/api'
import { AddPartnerModal, AddRepositoryModal } from '../../components/organisms'
import { PaymentAllocationForm } from './components'
import type { OpenInvoice } from '../../types/treasury'

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
}

export function PaymentForm() {
  const { t } = useTranslation(['treasury', 'common', 'sales'])
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [searchParams] = useSearchParams()
  const invoiceId = searchParams.get('invoice')
  const [showPartnerModal, setShowPartnerModal] = useState(false)
  const [showRepositoryModal, setShowRepositoryModal] = useState(false)
  const [createdPaymentId, setCreatedPaymentId] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
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
    queryKey: ['invoice', invoiceId],
    queryFn: async () => {
      if (!invoiceId) return null
      const response = await api.get<{ data: Invoice }>(`/invoices/${invoiceId}`)
      return response.data.data
    },
    enabled: !!invoiceId,
  })

  // Pre-fill form when invoice data is loaded
  useEffect(() => {
    if (invoiceData) {
      const amountResidual = invoiceData.amount_residual ?? parseFloat(invoiceData.total)
      reset({
        amount: amountResidual.toString(),
        payment_method_id: '',
        partner_id: invoiceData.partner_id,
        payment_date: new Date().toISOString().split('T')[0],
        reference: invoiceData.document_number,
        notes: t('treasury:payments.form.paymentForInvoice', { invoiceNumber: invoiceData.document_number }),
      })
    }
  }, [invoiceData, reset])

  // Fetch payment methods
  const { data: paymentMethodsData } = useQuery({
    queryKey: ['payment-methods'],
    queryFn: async () => {
      const response = await api.get<PaymentMethodsResponse>('/payment-methods')
      return response.data
    },
  })

  const paymentMethods = paymentMethodsData?.data ?? []

  // Fetch partners
  const { data: partnersData } = useQuery({
    queryKey: ['partners'],
    queryFn: async () => {
      const response = await api.get<PartnersResponse>('/partners')
      return response.data
    },
  })

  const partners = partnersData?.data ?? []

  // Fetch repositories
  const { data: repositoriesData } = useQuery({
    queryKey: ['payment-repositories'],
    queryFn: async () => {
      const response = await api.get<RepositoriesResponse>('/payment-repositories')
      return response.data
    },
  })

  const repositories = repositoriesData?.data ?? []

  // Fetch open invoices for selected partner (for smart allocation)
  const { data: openInvoicesData } = useQuery({
    queryKey: ['open-invoices', selectedPartnerId],
    queryFn: async () => {
      if (!selectedPartnerId) return null
      const response = await api.get<{ data: OpenInvoice[] }>(
        `/partners/${selectedPartnerId}/open-invoices`
      )
      return response.data.data
    },
    enabled: !!selectedPartnerId && !invoiceId, // Don't fetch if coming from specific invoice
  })

  const openInvoices: OpenInvoice[] = openInvoicesData ?? []

  const createMutation = useMutation({
    mutationFn: (data: PaymentFormData) => {
      // Prepare allocations array
      const allocations = []

      // If coming from a specific invoice, allocate to that invoice
      if (invoiceId && invoiceData) {
        const amountResidual = invoiceData.amount_residual ?? parseFloat(invoiceData.total)
        const allocationAmount = Math.min(parseFloat(data.amount), amountResidual)

        allocations.push({
          document_id: invoiceId,
          amount: allocationAmount.toFixed(2),
        })
      }
      // If no specific invoice, auto-allocate using FIFO to open invoices
      else if (openInvoices.length > 0 && selectedPartnerId) {
        let remainingAmount = parseFloat(data.amount)

        // Sort invoices by document_date (FIFO)
        const sortedInvoices = [...openInvoices].sort((a, b) =>
          new Date(a.document_date).getTime() - new Date(b.document_date).getTime()
        )

        for (const invoice of sortedInvoices) {
          if (remainingAmount <= 0) break

          const invoiceBalance = parseFloat(invoice.balance_due)
          const allocationAmount = Math.min(remainingAmount, invoiceBalance)

          allocations.push({
            document_id: invoice.id,
            amount: allocationAmount.toFixed(2),
          })

          remainingAmount -= allocationAmount
        }
      }

      return apiPost<Payment>('/payments', {
        ...data,
        amount: parseFloat(data.amount),
        allocations: allocations.length > 0 ? allocations : undefined,
      })
    },
    onSuccess: (payment) => {
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      void queryClient.invalidateQueries({ queryKey: ['invoice', invoiceId] })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
      void queryClient.invalidateQueries({ queryKey: ['open-invoices'] })
      toast.success(t('treasury:payments.messages.created'))

      // Store payment ID for potential manual allocation adjustment
      setCreatedPaymentId(payment.id)

      // If no open invoices or single invoice payment, navigate immediately
      if (openInvoices.length === 0 || invoiceId) {
        handleNavigateAway()
      }
      // Otherwise, user can optionally apply smart allocation below
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  // Navigate away after successful payment creation (and optional allocation)
  const handleNavigateAway = () => {
    if (invoiceId) {
      void navigate(`/sales/invoices/${invoiceId}`)
    } else {
      void navigate('/treasury/payments')
    }
  }

  // Handle allocation success
  const handleAllocationSuccess = () => {
    handleNavigateAway()
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
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('common:back')}
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">
          {invoiceData
            ? t('treasury:payments.newForInvoice', { invoiceNumber: invoiceData.document_number })
            : t('treasury:payments.new')}
        </h1>
      </div>

      {/* Form */}
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="space-y-6">
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Amount */}
            <div>
              <label
                htmlFor="amount"
                className="block text-sm font-medium text-gray-700"
              >
                {t('treasury:payments.form.amount')} *
              </label>
              <div className="relative mt-1">
                <span className="absolute start-3 top-1/2 -translate-y-1/2 text-gray-500">
                  $
                </span>
                <input
                  type="number"
                  step="0.01"
                  id="amount"
                  {...register('amount', {
                    required: t('treasury:payments.form.amountRequired'),
                    min: { value: 0.01, message: t('treasury:payments.form.amountPositive') },
                  })}
                  className="mt-1 block w-full rounded-lg border border-gray-300 ps-8 pe-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                />
              </div>
              {errors.amount && (
                <p className="mt-1 text-sm text-red-600">{errors.amount.message}</p>
              )}
            </div>

            {/* Payment Method */}
            <div>
              <label
                htmlFor="payment_method_id"
                className="block text-sm font-medium text-gray-700"
              >
                {t('treasury:payments.form.paymentMethod')} *
              </label>
              <select
                id="payment_method_id"
                {...register('payment_method_id', {
                  required: t('treasury:payments.form.paymentMethodRequired'),
                })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              >
                <option value="">{t('treasury:payments.form.selectMethod')}</option>
                {paymentMethods.map((method) => (
                  <option key={method.id} value={method.id}>
                    {method.name}
                  </option>
                ))}
              </select>
              {errors.payment_method_id && (
                <p className="mt-1 text-sm text-red-600">
                  {errors.payment_method_id.message}
                </p>
              )}
            </div>

            {/* Repository */}
            <div>
              <label
                htmlFor="repository_id"
                className="block text-sm font-medium text-gray-700"
              >
                {t('treasury:payments.form.repository')} *
              </label>
              <div className="mt-1 flex gap-2">
                <select
                  id="repository_id"
                  {...register('repository_id', { required: t('treasury:payments.form.repositoryRequired') })}
                  className="block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">{t('treasury:payments.form.selectRepository')}</option>
                  {repositories.map((repo) => (
                    <option key={repo.id} value={repo.id}>
                      {repo.name} ({repo.code})
                    </option>
                  ))}
                </select>
                <button
                  type="button"
                  onClick={() => { setShowRepositoryModal(true) }}
                  className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                  title={t('treasury:payments.form.addRepository')}
                >
                  <Plus className="h-4 w-4" />
                </button>
              </div>
              {errors.repository_id && (
                <p className="mt-1 text-sm text-red-600">
                  {errors.repository_id.message}
                </p>
              )}
            </div>

            {/* Partner */}
            <div>
              <label
                htmlFor="partner_id"
                className="block text-sm font-medium text-gray-700"
              >
                {t('treasury:payments.partner')} *
              </label>
              <div className="mt-1 flex gap-2">
                <select
                  id="partner_id"
                  {...register('partner_id', { required: t('treasury:payments.form.partnerRequired') })}
                  className="block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                >
                  <option value="">{t('treasury:payments.form.selectPartner')}</option>
                  {partners.map((partner) => (
                    <option key={partner.id} value={partner.id}>
                      {partner.name}
                    </option>
                  ))}
                </select>
                <button
                  type="button"
                  onClick={() => { setShowPartnerModal(true) }}
                  className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                  title={t('treasury:payments.form.addPartner')}
                >
                  <Plus className="h-4 w-4" />
                </button>
              </div>
              {errors.partner_id && (
                <p className="mt-1 text-sm text-red-600">
                  {errors.partner_id.message}
                </p>
              )}
            </div>

            {/* Payment Date */}
            <div>
              <label
                htmlFor="payment_date"
                className="block text-sm font-medium text-gray-700"
              >
                {t('treasury:payments.form.paymentDate')} *
              </label>
              <input
                type="date"
                id="payment_date"
                {...register('payment_date', {
                  required: t('treasury:payments.form.paymentDateRequired'),
                })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {errors.payment_date && (
                <p className="mt-1 text-sm text-red-600">
                  {errors.payment_date.message}
                </p>
              )}
            </div>

            {/* Reference */}
            <div>
              <label
                htmlFor="reference"
                className="block text-sm font-medium text-gray-700"
              >
                {t('treasury:payments.reference')}
              </label>
              <input
                type="text"
                id="reference"
                {...register('reference')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder={t('treasury:payments.form.referencePlaceholder')}
              />
            </div>

            {/* Notes */}
            <div className="sm:col-span-2">
              <label
                htmlFor="notes"
                className="block text-sm font-medium text-gray-700"
              >
                {t('treasury:payments.notes')}
              </label>
              <textarea
                id="notes"
                rows={3}
                {...register('notes')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder={t('treasury:payments.form.notesPlaceholder')}
              />
            </div>
          </div>
        </div>

        {/* Smart Payment Allocation Section */}
        {createdPaymentId && openInvoices.length > 0 && !invoiceId && (
          <div className="rounded-lg border border-gray-200 bg-white p-6">
            <div className="mb-4">
              <h3 className="text-lg font-semibold text-gray-900">
                {t('treasury:allocation.title')}
              </h3>
              <p className="mt-1 text-sm text-gray-600">
                {t('treasury:allocation.description')}
              </p>
            </div>

            <PaymentAllocationForm
              paymentId={createdPaymentId}
              partnerId={selectedPartnerId}
              paymentAmount={paymentAmount}
              invoices={openInvoices}
              onSuccess={handleAllocationSuccess}
              onCancel={handleNavigateAway}
            />
          </div>
        )}

        {/* Form Actions */}
        <div className="flex items-center justify-end gap-4">
          <Link
            to={invoiceId ? `/sales/invoices/${invoiceId}` : '/treasury/payments'}
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            {t('common:cancel')}
          </Link>
          <button
            type="submit"
            disabled={isSubmitting}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {isSubmitting ? t('common:saving') : t('common:save')}
          </button>
        </div>
      </form>

      {/* Add Partner Modal */}
      <AddPartnerModal
        isOpen={showPartnerModal}
        onClose={() => { setShowPartnerModal(false) }}
        onSuccess={(partner) => {
          setValue('partner_id', partner.id)
          void queryClient.invalidateQueries({ queryKey: ['partners'] })
        }}
      />

      {/* Add Repository Modal */}
      <AddRepositoryModal
        isOpen={showRepositoryModal}
        onClose={() => { setShowRepositoryModal(false) }}
        onSuccess={(repository) => {
          setValue('repository_id', repository.id)
          void queryClient.invalidateQueries({ queryKey: ['payment-repositories'] })
        }}
      />
    </div>
  )
}
