import { useState } from 'react'
import { Link, useParams, useLocation } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeft,
  Edit,
  Mail,
  Phone,
  Building2,
  Calendar,
  FileText,
  Receipt,
  CreditCard,
  Car,
  DollarSign,
  Wallet,
  PiggyBank,
  Plus,
} from 'lucide-react'
import { api } from '../../lib/api'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/ui/Tabs'
import { AddVehicleModal } from '../../components/organisms'

interface PartnerAccountBalance {
  partner_id: string
  currency: string
  unallocated_balance: string
  deposit_count: number
}

interface Partner {
  id: string
  name: string
  type: 'customer' | 'supplier' | 'both'
  email: string | null
  phone: string | null
  address: string | null
  city: string | null
  postal_code: string | null
  country: string | null
  tax_id: string | null
  notes: string | null
  is_active: boolean
  total_receivable?: number
  total_payable?: number
  created_at: string
  updated_at: string
}

interface Document {
  id: string
  document_number: string
  type: 'quote' | 'sales_order' | 'invoice' | 'credit_note' | 'purchase_order'
  status: 'draft' | 'confirmed' | 'posted' | 'cancelled'
  fiscal_category: 'NON_FISCAL' | 'FISCAL_RECEIPT' | 'TAX_INVOICE' | 'CREDIT_NOTE'
  fiscal_status: 'DRAFT' | 'SEALED' | 'VOIDED'
  is_sealed: boolean
  is_fiscal: boolean
  total: string | null
  document_date: string
}

interface Payment {
  id: string
  payment_number: string
  amount: string
  payment_date: string
  payment_method_name: string | null
  status: string
  payment_type: string | null
  unallocated_amount: string
}

interface Vehicle {
  id: string
  license_plate: string
  make: string
  model: string
  year: number
  vin: string | null
}

const typeColors = {
  customer: 'bg-blue-100 text-blue-800',
  supplier: 'bg-purple-100 text-purple-800',
  both: 'bg-green-100 text-green-800',
}

const typeLabels = {
  customer: 'Customer',
  supplier: 'Supplier',
  both: 'Both',
}

const documentTypeColors: Record<string, string> = {
  quote: 'bg-yellow-100 text-yellow-800',
  sales_order: 'bg-blue-100 text-blue-800',
  invoice: 'bg-green-100 text-green-800',
  credit_note: 'bg-red-100 text-red-800',
  purchase_order: 'bg-purple-100 text-purple-800',
}

const documentTypeLabels: Record<string, string> = {
  quote: 'Quote',
  sales_order: 'Sales Order',
  invoice: 'Invoice',
  credit_note: 'Credit Note',
  purchase_order: 'Purchase Order',
}

const statusColors: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  confirmed: 'bg-blue-100 text-blue-800',
  posted: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
}

export function PartnerDetailPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { id = '' } = useParams<{ id: string }>()
  const location = useLocation()
  const [showVehicleModal, setShowVehicleModal] = useState(false)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  // Determine context from URL
  const isCustomerContext = location.pathname.includes('/sales/customers')
  const isSupplierContext = location.pathname.includes('/purchases/suppliers')

  const basePath = isCustomerContext
    ? '/sales/customers'
    : isSupplierContext
      ? '/purchases/suppliers'
      : '/partners'

  const entityLabel = isCustomerContext ? 'Customer' : isSupplierContext ? 'Supplier' : 'Partner'

  const { data: partner, isLoading, error } = useQuery({
    queryKey: ['partner', id],
    queryFn: async () => {
      const response = await api.get<{ data: Partner }>(`/partners/${id}`)
      return response.data.data
    },
    enabled: id.length > 0,
  })

  // Fetch related documents (both sales docs for customers and purchase orders for suppliers)
  const { data: documentsData } = useQuery({
    queryKey: ['partner-documents', id, isSupplierContext],
    queryFn: async () => {
      const typeFilter = isSupplierContext ? '&type=purchase_order' : ''
      const response = await api.get<{ data: Document[] }>(`/documents?partner_id=${id}${typeFilter}`)
      return response.data.data
    },
    enabled: id.length > 0 && (isCustomerContext || isSupplierContext),
  })

  // Fetch related payments
  const { data: paymentsData } = useQuery({
    queryKey: ['partner-payments', id],
    queryFn: async () => {
      const response = await api.get<{ data: Payment[] }>(`/payments?partner_id=${id}`)
      return response.data.data
    },
    enabled: id.length > 0,
  })

  // Fetch related vehicles (for customers)
  const { data: vehiclesData } = useQuery({
    queryKey: ['partner-vehicles', id],
    queryFn: async () => {
      const response = await api.get<{ data: Vehicle[] }>(`/vehicles?customer_id=${id}`)
      return response.data.data
    },
    enabled: id.length > 0 && isCustomerContext,
  })

  // Fetch partner account balance (unallocated deposits/credits)
  const { data: accountBalance } = useQuery({
    queryKey: ['partner-account-balance', id],
    queryFn: async () => {
      // Default to TND for now - in a real app, this would come from tenant settings
      const response = await api.get<{ data: PartnerAccountBalance }>(`/partners/${id}/account-balance/TND`)
      return response.data.data
    },
    enabled: id.length > 0,
  })

  const documents = documentsData ?? []
  const payments = paymentsData ?? []
  const vehicles = vehiclesData ?? []

  // Format currency using company settings
  const formatAmount = (amount: number) => {
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('status.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="space-y-4">
        <Link
          to={basePath}
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {entityLabel} not found or an error occurred.
        </div>
      </div>
    )
  }

  if (!partner) {
    return null
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to={basePath}
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">{partner.name}</h1>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[partner.type]}`}
              >
                {typeLabels[partner.type]}
              </span>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  partner.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
                }`}
              >
                {partner.is_active ? t('status.active') : t('status.inactive')}
              </span>
            </div>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {isCustomerContext && (
            <>
              <Link
                to={`/sales/quotes/new?customer=${partner.id}`}
                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
              >
                <FileText className="h-4 w-4" />
                {t('actions.newQuote')}
              </Link>
              <Link
                to={`/sales/invoices/new?customer=${partner.id}`}
                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
              >
                <Receipt className="h-4 w-4" />
                {t('actions.newInvoice')}
              </Link>
            </>
          )}
          {isSupplierContext && (
            <Link
              to={`/purchases/orders/new?supplier=${partner.id}`}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
            >
              <FileText className="h-4 w-4" />
              {t('actions.newPurchaseOrder', 'New Purchase Order')}
            </Link>
          )}
          <Link
            to={`${basePath}/${partner.id}/edit`}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
          >
            <Edit className="h-4 w-4" />
            {t('actions.edit')}
          </Link>
        </div>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview">{t('tabs.overview')}</TabsTrigger>
          {(isCustomerContext || isSupplierContext) && (
            <TabsTrigger value="documents">
              {isSupplierContext ? t('tabs.purchaseOrders', 'Purchase Orders') : t('tabs.documents')} ({documents.length})
            </TabsTrigger>
          )}
          <TabsTrigger value="payments">
            {t('tabs.payments')} ({payments.length})
          </TabsTrigger>
          {isCustomerContext && (
            <TabsTrigger value="vehicles">
              {t('tabs.vehicles')} ({vehicles.length})
            </TabsTrigger>
          )}
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview" className="mt-6">
          <div className="grid gap-6 lg:grid-cols-3">
            {/* Balance Card */}
            <div className="rounded-lg border border-gray-200 bg-white p-6">
              <h2 className="mb-4 text-lg font-semibold text-gray-900 flex items-center gap-2">
                <DollarSign className="h-5 w-5 text-gray-400" />
                {t('sections.balance')}
              </h2>
              <dl className="space-y-3">
                {/* Total Receivable - for customers */}
                {isCustomerContext && (
                  <div className="flex justify-between">
                    <dt className="text-sm text-gray-500">{t('fields.totalReceivable')}</dt>
                    <dd className="text-sm font-medium text-gray-900">
                      {formatAmount(partner.total_receivable ?? 0)}
                    </dd>
                  </div>
                )}

                {/* Total Payable - for suppliers */}
                {isSupplierContext && (
                  <div className="flex justify-between">
                    <dt className="text-sm text-gray-500">{t('fields.totalPayable')}</dt>
                    <dd className="text-sm font-medium text-gray-900">
                      {formatAmount(partner.total_payable ?? 0)}
                    </dd>
                  </div>
                )}

                {/* Unallocated Balance / Credit */}
                {accountBalance && parseFloat(accountBalance.unallocated_balance) > 0 && (
                  <>
                    <div className="border-t border-gray-100 pt-3">
                      <div className="flex justify-between items-center">
                        <dt className="text-sm text-gray-500 flex items-center gap-2">
                          <Wallet className="h-4 w-4 text-green-500" />
                          {t('partner.unallocatedBalance')}
                        </dt>
                        <dd className="text-sm font-medium text-green-600">
                          {formatAmount(parseFloat(accountBalance.unallocated_balance))}
                        </dd>
                      </div>
                      <p className="mt-1 text-xs text-gray-400">
                        {t('partner.unallocatedBalanceHint', { count: accountBalance.deposit_count })}
                      </p>
                    </div>
                  </>
                )}

                {/* On Account Credit */}
                {accountBalance && parseFloat(accountBalance.unallocated_balance) > 0 && (
                  <div className="bg-green-50 -mx-2 px-2 py-2 rounded">
                    <div className="flex items-center gap-2 text-green-700">
                      <PiggyBank className="h-4 w-4" />
                      <span className="text-xs font-medium">{t('partner.creditAvailable')}</span>
                    </div>
                  </div>
                )}

                {/* No balance */}
                {(!accountBalance || parseFloat(accountBalance.unallocated_balance) === 0) &&
                 (partner.total_receivable ?? 0) === 0 &&
                 (partner.total_payable ?? 0) === 0 && (
                  <div className="text-sm text-gray-400 text-center py-2">
                    {t('partner.noBalance')}
                  </div>
                )}
              </dl>
            </div>

            {/* Contact Information */}
            <div className="rounded-lg border border-gray-200 bg-white p-6">
              <h2 className="mb-4 text-lg font-semibold text-gray-900">
                {t('sections.contactInfo')}
              </h2>
              <dl className="space-y-4">
                {partner.email && (
                  <div className="flex items-start gap-3">
                    <Mail className="mt-0.5 h-5 w-5 text-gray-400" />
                    <div>
                      <dt className="text-sm font-medium text-gray-500">{t('fields.email')}</dt>
                      <dd className="text-gray-900">{partner.email}</dd>
                    </div>
                  </div>
                )}
                {partner.phone && (
                  <div className="flex items-start gap-3">
                    <Phone className="mt-0.5 h-5 w-5 text-gray-400" />
                    <div>
                      <dt className="text-sm font-medium text-gray-500">{t('fields.phone')}</dt>
                      <dd className="text-gray-900">{partner.phone}</dd>
                    </div>
                  </div>
                )}
                {(partner.address != null || partner.city != null) && (
                  <div className="flex items-start gap-3">
                    <Building2 className="mt-0.5 h-5 w-5 text-gray-400" />
                    <div>
                      <dt className="text-sm font-medium text-gray-500">{t('fields.address')}</dt>
                      <dd className="text-gray-900">
                        {partner.address && <div>{partner.address}</div>}
                        {(partner.city != null || partner.postal_code != null) && (
                          <div>
                            {partner.postal_code} {partner.city}
                          </div>
                        )}
                        {partner.country && <div>{partner.country}</div>}
                      </dd>
                    </div>
                  </div>
                )}
              </dl>
            </div>

            {/* Business Information */}
            <div className="rounded-lg border border-gray-200 bg-white p-6">
              <h2 className="mb-4 text-lg font-semibold text-gray-900">
                {t('sections.businessInfo')}
              </h2>
              <dl className="space-y-4">
                {partner.tax_id && (
                  <div>
                    <dt className="text-sm font-medium text-gray-500">{t('fields.taxId')}</dt>
                    <dd className="text-gray-900">{partner.tax_id}</dd>
                  </div>
                )}
                <div className="flex items-start gap-3">
                  <Calendar className="mt-0.5 h-5 w-5 text-gray-400" />
                  <div>
                    <dt className="text-sm font-medium text-gray-500">{t('fields.created')}</dt>
                    <dd className="text-gray-900">
                      {new Date(partner.created_at).toLocaleDateString()}
                    </dd>
                  </div>
                </div>
              </dl>
            </div>

            {/* Notes */}
            {partner.notes && (
              <div className="rounded-lg border border-gray-200 bg-white p-6 lg:col-span-3">
                <h2 className="mb-4 text-lg font-semibold text-gray-900">{t('fields.notes')}</h2>
                <p className="whitespace-pre-wrap text-gray-700">{partner.notes}</p>
              </div>
            )}
          </div>
        </TabsContent>

        {/* Documents Tab */}
        {(isCustomerContext || isSupplierContext) && (
          <TabsContent value="documents" className="mt-6">
            {documents.length === 0 ? (
              <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
                <FileText className="mx-auto h-12 w-12 text-gray-400" />
                <h3 className="mt-2 text-sm font-semibold text-gray-900">
                  {isSupplierContext
                    ? t('status.noPurchaseOrders', 'No purchase orders')
                    : t('status.noDocuments')}
                </h3>
                <p className="mt-1 text-sm text-gray-500">
                  {isSupplierContext
                    ? t('status.noPurchaseOrdersDescription', 'Get started by creating a purchase order for this supplier.')
                    : t('status.noDocumentsDescription')}
                </p>
                <div className="mt-6 flex justify-center gap-3">
                  {isSupplierContext ? (
                    <Link
                      to={`/purchases/orders/new?supplier=${partner.id}`}
                      className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    >
                      <FileText className="h-4 w-4" />
                      {t('actions.newPurchaseOrder', 'New Purchase Order')}
                    </Link>
                  ) : (
                    <Link
                      to={`/sales/quotes/new?customer=${partner.id}`}
                      className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    >
                      <FileText className="h-4 w-4" />
                      {t('actions.newQuote')}
                    </Link>
                  )}
                </div>
              </div>
            ) : (
              <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.documentNumber')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.type')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.status')}
                      </th>
                      <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.amount')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.date')}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {documents.map((doc) => {
                      // Determine the correct path based on document type
                      const getDocumentPath = () => {
                        if (doc.type === 'purchase_order') {
                          return `/purchases/orders/${doc.id}`
                        }
                        if (doc.type === 'quote') {
                          return `/sales/quotes/${doc.id}`
                        }
                        if (doc.type === 'sales_order') {
                          return `/sales/orders/${doc.id}`
                        }
                        return `/sales/invoices/${doc.id}`
                      }

                      return (
                        <tr key={doc.id} className="hover:bg-gray-50">
                          <td className="whitespace-nowrap px-6 py-4">
                            <Link
                              to={getDocumentPath()}
                              className="font-medium text-blue-600 hover:text-blue-900"
                            >
                              {doc.document_number}
                            </Link>
                          </td>
                          <td className="whitespace-nowrap px-6 py-4">
                            <span
                              className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${documentTypeColors[doc.type]}`}
                            >
                              {documentTypeLabels[doc.type]}
                            </span>
                          </td>
                          <td className="whitespace-nowrap px-6 py-4">
                            <span
                              className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[doc.status]}`}
                            >
                              {doc.status}
                            </span>
                          </td>
                          <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                            {formatAmount(parseFloat(doc.total ?? '0'))}
                          </td>
                          <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                            {new Date(doc.document_date).toLocaleDateString()}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </TabsContent>
        )}

        {/* Payments Tab */}
        <TabsContent value="payments" className="mt-6">
          {payments.length === 0 ? (
            <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
              <CreditCard className="mx-auto h-12 w-12 text-gray-400" />
              <h3 className="mt-2 text-sm font-semibold text-gray-900">
                {t('status.noPayments')}
              </h3>
              <p className="mt-1 text-sm text-gray-500">{t('status.noPaymentsDescription')}</p>
            </div>
          ) : (
            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.paymentNumber')}
                    </th>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.method')}
                    </th>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.status')}
                    </th>
                    <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.amount')}
                    </th>
                    <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('fields.date')}
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {payments.map((payment) => (
                    <tr key={payment.id} className="hover:bg-gray-50">
                      <td className="whitespace-nowrap px-6 py-4">
                        <Link
                          to={`/treasury/payments/${payment.id}`}
                          className="font-medium text-blue-600 hover:text-blue-900"
                        >
                          {payment.payment_number}
                        </Link>
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                        {payment.payment_method_name ?? '-'}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        <div className="flex items-center gap-2">
                          <span
                            className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[payment.status] ?? 'bg-gray-100 text-gray-800'}`}
                          >
                            {payment.status}
                          </span>
                          {payment.payment_type === 'advance' && (
                            <span className="inline-flex rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                              {t('treasury:payments.types.advance')}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-end">
                        <div className="text-sm font-medium text-gray-900">
                          {formatAmount(parseFloat(payment.amount))}
                        </div>
                        {parseFloat(payment.unallocated_amount) > 0 && (
                          <div className="text-xs text-blue-600">
                            {t('treasury:payments.creditBalance')}: {formatAmount(parseFloat(payment.unallocated_amount))}
                          </div>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                        {new Date(payment.payment_date).toLocaleDateString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </TabsContent>

        {/* Vehicles Tab */}
        {isCustomerContext && (
          <TabsContent value="vehicles" className="mt-6">
            {vehicles.length === 0 ? (
              <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
                <Car className="mx-auto h-12 w-12 text-gray-400" />
                <h3 className="mt-2 text-sm font-semibold text-gray-900">
                  {t('status.noVehicles')}
                </h3>
                <p className="mt-1 text-sm text-gray-500">{t('status.noVehiclesDescription')}</p>
                <div className="mt-6">
                  <button
                    type="button"
                    onClick={() => { setShowVehicleModal(true) }}
                    className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
                  >
                    <Car className="h-4 w-4" />
                    {t('actions.addVehicle')}
                  </button>
                </div>
              </div>
            ) : (
              <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.licensePlate')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.vehicle')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.year')}
                      </th>
                      <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                        {t('fields.vin')}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 bg-white">
                    {vehicles.map((vehicle) => (
                      <tr key={vehicle.id} className="hover:bg-gray-50">
                        <td className="whitespace-nowrap px-6 py-4">
                          <Link
                            to={`/vehicles/${vehicle.id}`}
                            className="font-medium text-blue-600 hover:text-blue-900"
                          >
                            {vehicle.license_plate}
                          </Link>
                        </td>
                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                          {vehicle.make} {vehicle.model}
                        </td>
                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                          {vehicle.year}
                        </td>
                        <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                          {vehicle.vin ?? '-'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                <div className="border-t border-gray-200 bg-gray-50 px-6 py-4">
                  <button
                    type="button"
                    onClick={() => { setShowVehicleModal(true) }}
                    className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                  >
                    <Plus className="h-4 w-4" />
                    {t('actions.addVehicle')}
                  </button>
                </div>
              </div>
            )}
          </TabsContent>
        )}
      </Tabs>

      {/* Add Vehicle Modal */}
      <AddVehicleModal
        isOpen={showVehicleModal}
        onClose={() => { setShowVehicleModal(false) }}
        partnerId={partner.id}
        onSuccess={() => {
          void queryClient.invalidateQueries({ queryKey: ['partner-vehicles', id] })
        }}
      />
    </div>
  )
}
