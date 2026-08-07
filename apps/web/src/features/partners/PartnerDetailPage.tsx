import { useState } from 'react'
import { Link, useNavigate, useParams, useLocation, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
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
  DollarSign,
  Wallet,
  PiggyBank,
  Plus,
  Trash2,
} from 'lucide-react'
import { api, isApiError } from '../../lib/api'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { formatCurrency } from '../../lib/format'
import { bccomp } from '../../lib/decimal'
import { usePartnerBalanceRealtime } from './hooks/usePartnerBalanceRealtime'
import { usePartnerDeposits } from './hooks/usePartnerDeposits'
import { RecordDepositModal } from './RecordDepositModal'
import { Button } from '../../components/atoms/Button'
import { Tabs, TabsList, TabsTrigger, TabsContent } from '../../components/molecules/Tabs'
import { AddVehicleModal } from '../../components/organisms/AddVehicleModal/AddVehicleModal'
import { VehiclesTab } from '../vehicles/components/organisms/VehiclesTab'
import { usePartnerVehicles } from '../vehicles/hooks/usePartnerVehicles'
import { partnersInvalidationPredicate, partnerVehiclesInvalidationPredicate } from './_invalidation'
import { useCompanyConfig } from '@/contexts'
import { OffsetPagination } from '@/components/ui/OffsetPagination'
import { usePermissions } from '@/hooks/usePermissions'
import { PartnerLoyaltyCard } from '@/features/loyalty'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'
import type { OffsetPaginationMeta } from '@/types/pagination'

interface PartnerAccountBalance {
  partner_id: string
  currency: string
  unallocated_balance: string
  deposit_count: number
}

// Source of truth is the backend resource (Rule 7: types flow from backend).
// Balances are decimal strings (receivable_balance / payable_balance), and the
// address/tax fields are street_address / vat_number — NOT total_receivable /
// total_payable / address / tax_id as the previous hand-rolled interface assumed.
type Partner = App.Modules.Partner.Application.DTOs.PartnerData

interface Document {
  id: string
  document_number: string | null
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

interface DocumentsResponse {
  data: Document[]
  meta?: Partial<OffsetPaginationMeta>
}

interface PaymentsResponse {
  data: Payment[]
  meta?: Partial<OffsetPaginationMeta>
}

const typeColors = {
  customer: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  supplier: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
  both: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
}

const documentTypeColors: Record<string, string> = {
  quote: `${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`,
  sales_order: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  invoice: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
  credit_note: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
  purchase_order: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
}

const documentWorkflowClasses: Record<string, string> = {
  draft: `${colorTokens.surface.muted} ${colorTokens.text.strong}`,
  confirmed: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  posted: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
  cancelled: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
}

const PARTNER_DETAIL_TABS = ['overview', 'documents', 'payments', 'vehicles', 'deposits'] as const
type PartnerDetailTab = typeof PARTNER_DETAIL_TABS[number]

function isPartnerDetailTab(value: string | null): value is PartnerDetailTab {
  return value !== null && (PARTNER_DETAIL_TABS as readonly string[]).includes(value)
}

export function PartnerDetailPage() {
  usePartnerBalanceRealtime()
  const { t } = useTranslation(['common', 'deposits', 'treasury', 'sales'])
  const queryClient = useQueryClient()
  const { id = '' } = useParams<{ id: string }>()
  const location = useLocation()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const [showVehicleModal, setShowVehicleModal] = useState(false)
  const [showDeleteDialog, setShowDeleteDialog] = useState(false)
  const [showDepositModal, setShowDepositModal] = useState(false)
  const [documentsPage, setDocumentsPage] = useState(1)
  const [documentsPerPage, setDocumentsPerPage] = useState(10)
  const [paymentsPage, setPaymentsPage] = useState(1)
  const [paymentsPerPage, setPaymentsPerPage] = useState(10)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const currentCompany = useCompanyStore((state) => state.getCurrentCompany())
  const { hasModule } = useCompanyConfig()
  const { hasPermission } = usePermissions()
  const hasTenantScope = tenantId !== null && companyId !== null
  const tabParam = searchParams.get('tab')
  const activeTab: PartnerDetailTab = isPartnerDetailTab(tabParam) ? tabParam : 'overview'

  const handleTabChange = (tab: string) => {
    const next = new URLSearchParams(searchParams)
    next.set('tab', tab)
    setSearchParams(next)
  }

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

  const entityLabel = isCustomerContext ? t('common:partners.customer') : isSupplierContext ? t('common:partners.supplier') : t('common:partners.partner')

  const { data: partner, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['partner', id]),
    queryFn: async () => {
      const response = await api.get<{ data: Partner }>(`/partners/${id}`)
      return response.data.data
    },
    enabled: id.length > 0 && hasTenantScope,
  })

  // Fetch related documents (both sales docs for customers and purchase orders for suppliers)
  const { data: documentsData } = useQuery({
    queryKey: tenantScopedKey(['partner-documents', id, isSupplierContext, documentsPage, documentsPerPage]),
    queryFn: async () => {
      const params = new URLSearchParams({
        partner_id: id,
        page: String(documentsPage),
        per_page: String(documentsPerPage),
      })
      if (isSupplierContext) params.set('type', 'purchase_order')
      const response = await api.get<DocumentsResponse>(`/documents?${params.toString()}`)
      return response.data
    },
    enabled: id.length > 0 && hasTenantScope && (isCustomerContext || isSupplierContext),
  })

  // Fetch related payments
  const { data: paymentsData } = useQuery({
    queryKey: tenantScopedKey(['partner-payments', id, paymentsPage, paymentsPerPage]),
    queryFn: async () => {
      const params = new URLSearchParams({
        partner_id: id,
        page: String(paymentsPage),
        per_page: String(paymentsPerPage),
      })
      const response = await api.get<PaymentsResponse>(`/payments?${params.toString()}`)
      return response.data
    },
    enabled: id.length > 0 && hasTenantScope,
  })

  // Fetch related vehicles (for customers) — uses the ownership-aware endpoint via the
  // shared VehiclesTab organism. We still read the count here to surface it in the tab trigger.
  const showVehiclesTab =
    isCustomerContext &&
    hasModule('Vehicle') &&
    (partner?.type === 'customer' || partner?.type === 'both')
  const showDepositsTab = isCustomerContext && (partner?.type === 'customer' || partner?.type === 'both')
  const showLoyaltyCard =
    isCustomerContext &&
    hasModule('Loyalty') &&
    (partner?.type === 'customer' || partner?.type === 'both') &&
    hasPermission('loyalty.enroll')

  const { data: deposits = [] } = usePartnerDeposits(id)
  const { data: partnerVehiclesData } = usePartnerVehicles(showVehiclesTab ? id : undefined)

  // Fetch partner account balance (unallocated deposits/credits)
  const { data: accountBalance } = useQuery({
    queryKey: tenantScopedKey(['partner-account-balance', id]),
    queryFn: async () => {
      // Default to TND for now - in a real app, this would come from tenant settings
      const response = await api.get<{ data: PartnerAccountBalance }>(`/partners/${id}/account-balance/TND`)
      return response.data.data
    },
    enabled: id.length > 0 && hasTenantScope,
  })

  // BUG-007: there was no delete control at all. The backend endpoint and the
  // `partners.delete` permission (admin-only) already existed.
  const canDeletePartner = hasPermission('partners.delete')

  const deleteMutation = useMutation({
    mutationFn: async () => {
      await api.delete(`/partners/${id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: partnersInvalidationPredicate(tenantId, companyId),
      })
      toast.success(t('sales:partners.delete.success', { entity: entityLabel }))
      void navigate(basePath)
    },
    onError: (mutationError: unknown) => {
      // The server refuses (409 PARTNER_HAS_DOCUMENTS) when invoices, payments
      // or POS receipts still reference the partner. Surface the actionable,
      // translated message rather than the raw English server string.
      if (
        isApiError(mutationError) &&
        mutationError.response?.status === 409 &&
        mutationError.response.data.error.code === 'PARTNER_HAS_DOCUMENTS'
      ) {
        toast.error(t('sales:partners.delete.blocked', { name: partner?.name ?? '' }))
        return
      }
      // Anything else: a translated generic failure, not the raw English
      // server-authored `error.message`. The detail goes to the console for
      // support rather than to the operator.
      console.error('Partner delete failed', mutationError)
      toast.error(t('sales:partners.delete.failed', { entity: entityLabel }))
    },
  })

  const documents = documentsData?.data ?? []
  const payments = paymentsData?.data ?? []
  const documentsMeta = documentsData?.meta
  const paymentsMeta = paymentsData?.meta
  const vehicleCount = partnerVehiclesData?.meta.total ?? partnerVehiclesData?.data.length ?? 0

  // Format currency using company settings
  const formatAmount = (amount: string | number) => {
    return formatCurrency(amount, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }
  const getDocumentNumberLabel = (documentNumber: string | null) =>
    documentNumber ?? t('sales:documents.draftNumberPlaceholder')

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={colorTokens.text.subtle}>{t('status.loading')}</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="space-y-4">
        <Link
          to={basePath}
          className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
        <div className={`rounded-lg ${colorTokens.intent.danger.bgSubtle} p-4 ${colorTokens.intent.danger.textStrong}`}>
          {t('common:partner.notFoundError', { entity: entityLabel })}
        </div>
      </div>
    )
  }

  if (!partner) {
    return null
  }

  const postalCityLine = [partner.postal_code, partner.city].filter(Boolean).join(' ')
  const addressLines = [
    partner.street_address,
    partner.street_address_2,
    postalCityLine,
    partner.country,
  ].filter((line): line is string => typeof line === 'string' && line.trim().length > 0)

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to={basePath}
            className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest}`}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-3">
              <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{partner.name}</PageHeaderTitle>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[partner.type]}`}
              >
                {t(`common:partners.${partner.type}`)}
              </span>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                  partner.is_active ? `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}` : `${colorTokens.surface.muted} ${colorTokens.text.strong}`
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
                className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
              >
                <FileText className="h-4 w-4" />
                {t('actions.newQuote')}
              </Link>
              <Link
                to={`/sales/invoices/new?customer=${partner.id}`}
                className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
              >
                <Receipt className="h-4 w-4" />
                {t('actions.newInvoice')}
              </Link>
              <button
                type="button"
                onClick={() => { setShowDepositModal(true); }}
                className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
              >
                <Wallet className="h-4 w-4" />
                {t('deposits:recordButton')}
              </button>
            </>
          )}
          {isSupplierContext && (
            <Link
              to={`/purchases/orders/new?supplier=${partner.id}`}
              className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
            >
              <FileText className="h-4 w-4" />
              {t('actions.newPurchaseOrder')}
            </Link>
          )}
          <Link
            to={`${basePath}/${partner.id}/edit`}
            className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} transition-colors`}
          >
            <Edit className="h-4 w-4" />
            {t('actions.edit')}
          </Link>
          {canDeletePartner && (
            <Button
              variant="danger"
              onClick={() => { setShowDeleteDialog(true) }}
              disabled={deleteMutation.isPending}
            >
              <Trash2 className="me-2 h-4 w-4" />
              {deleteMutation.isPending ? t('status.saving') : t('actions.delete')}
            </Button>
          )}
        </div>
      </div>

      {/* Tabs */}
      <Tabs defaultValue="overview" value={activeTab} onChange={handleTabChange}>
        <TabsList>
          <TabsTrigger value="overview">{t('tabs.overview')}</TabsTrigger>
          {(isCustomerContext || isSupplierContext) && (
            <TabsTrigger value="documents">
              {isSupplierContext ? t('tabs.purchaseOrders') : t('tabs.documents')} ({documents.length})
            </TabsTrigger>
          )}
          <TabsTrigger value="payments">
            {t('tabs.payments')} ({payments.length})
          </TabsTrigger>
          {showVehiclesTab && (
            <TabsTrigger value="vehicles">
              {t('tabs.vehicles')} ({vehicleCount})
            </TabsTrigger>
          )}
          {showDepositsTab && (
            <TabsTrigger value="deposits">
              {t('deposits:tabLabel')} ({deposits.length})
            </TabsTrigger>
          )}
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview" className="mt-6">
          <div className="grid gap-6 lg:grid-cols-3">
            {/* Balance Card */}
            <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
              <h2 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary} flex items-center gap-2`}>
                <DollarSign className={`h-5 w-5 ${colorTokens.text.disabled}`} />
                {t('sections.balance')}
              </h2>
              <dl className="space-y-3">
                {/* Total Receivable - for customers */}
                {isCustomerContext && (
                  <div className="flex justify-between">
                    <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('fields.totalReceivable')}</dt>
                    <dd className={`text-sm font-medium ${colorTokens.text.primary}`}>
                      {formatAmount(partner.receivable_balance ?? '0')}
                    </dd>
                  </div>
                )}

                {/* Total Payable - for suppliers */}
                {isSupplierContext && (
                  <div className="flex justify-between">
                    <dt className={`text-sm ${colorTokens.text.subtle}`}>{t('fields.totalPayable')}</dt>
                    <dd className={`text-sm font-medium ${colorTokens.text.primary}`}>
                      {formatAmount(partner.payable_balance ?? '0')}
                    </dd>
                  </div>
                )}

                {/* Unallocated Balance / Credit */}
                {accountBalance && bccomp(accountBalance.unallocated_balance, '0') > 0 && (
                  <>
                    <div className={`border-t ${colorTokens.border.hairline} pt-3`}>
                      <div className="flex justify-between items-center">
                        <dt className={`text-sm ${colorTokens.text.subtle} flex items-center gap-2`}>
                          <Wallet className={`h-4 w-4 ${colorTokens.intent.success.textSubtle}`} />
                          {t('partner.unallocatedBalance')}
                        </dt>
                        <dd className={`text-sm font-medium ${colorTokens.intent.success.text}`}>
                          {formatAmount(accountBalance.unallocated_balance)}
                        </dd>
                      </div>
                      <p className={`mt-1 text-xs ${colorTokens.text.disabled}`}>
                        {t('partner.unallocatedBalanceHint', { count: accountBalance.deposit_count })}
                      </p>
                    </div>
                  </>
                )}

                {/* On Account Credit */}
                {accountBalance && bccomp(accountBalance.unallocated_balance, '0') > 0 && (
                  <div className={`${colorTokens.intent.success.bgSubtle} -mx-2 px-2 py-2 rounded`}>
                    <div className={`flex items-center gap-2 ${colorTokens.intent.success.textStrong}`}>
                      <PiggyBank className="h-4 w-4" />
                      <span className="text-xs font-medium">{t('partner.creditAvailable')}</span>
                    </div>
                  </div>
                )}

                {/* No balance */}
                {(!accountBalance || bccomp(accountBalance.unallocated_balance, '0') === 0) &&
                 bccomp(partner.receivable_balance ?? '0', '0') === 0 &&
                 bccomp(partner.payable_balance ?? '0', '0') === 0 && (
                  <div className={`text-sm ${colorTokens.text.disabled} text-center py-2`}>
                    {t('partner.noBalance')}
                  </div>
                )}
              </dl>
            </div>

            {/* Contact Information */}
            <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
              <h2 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('sections.contactInfo')}
              </h2>
              <dl className="space-y-4">
                {partner.email && (
                  <div className="flex items-start gap-3">
                    <Mail className={`mt-0.5 h-5 w-5 ${colorTokens.text.disabled}`} />
                    <div>
                      <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('fields.email')}</dt>
                      <dd className={colorTokens.text.primary}>{partner.email}</dd>
                    </div>
                  </div>
                )}
                {partner.phone && (
                  <div className="flex items-start gap-3">
                    <Phone className={`mt-0.5 h-5 w-5 ${colorTokens.text.disabled}`} />
                    <div>
                      <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('fields.phone')}</dt>
                      <dd className={colorTokens.text.primary}>{partner.phone}</dd>
                    </div>
                  </div>
                )}
                {addressLines.length > 0 && (
                  <div className="flex items-start gap-3">
                    <Building2 className={`mt-0.5 h-5 w-5 ${colorTokens.text.disabled}`} />
                    <div>
                      <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('fields.address')}</dt>
                      <dd className={colorTokens.text.primary}>
                        {addressLines.map((line) => (
                          <div key={line}>{line}</div>
                        ))}
                      </dd>
                    </div>
                  </div>
                )}
              </dl>
            </div>

            {/* Business Information */}
            <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
              <h2 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>
                {t('sections.businessInfo')}
              </h2>
              <dl className="space-y-4">
                {partner.vat_number && (
                  <div>
                    <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('fields.taxId')}</dt>
                    <dd className={colorTokens.text.primary}>{partner.vat_number}</dd>
                  </div>
                )}
                <div className="flex items-start gap-3">
                  <Calendar className={`mt-0.5 h-5 w-5 ${colorTokens.text.disabled}`} />
                  <div>
                    <dt className={`text-sm font-medium ${colorTokens.text.subtle}`}>{t('fields.created')}</dt>
                    <dd className={colorTokens.text.primary}>
                      {new Date(partner.created_at).toLocaleDateString()}
                    </dd>
                  </div>
                </div>
              </dl>
            </div>

            {/* Loyalty Card */}
            {showLoyaltyCard && (
              <PartnerLoyaltyCard partnerId={id} partnerPhone={partner.phone ?? null} />
            )}

            {/* Notes */}
            {partner.notes && (
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6 lg:col-span-3`}>
                <h2 className={`mb-4 text-lg font-semibold ${colorTokens.text.primary}`}>{t('fields.notes')}</h2>
                <p className={`whitespace-pre-wrap ${colorTokens.text.secondary}`}>{partner.notes}</p>
              </div>
            )}
          </div>
        </TabsContent>

        {/* Documents Tab */}
        {(isCustomerContext || isSupplierContext) && (
          <TabsContent value="documents" className="mt-6">
            {documents.length === 0 ? (
              <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} p-12 text-center`}>
                <FileText className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
                <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
                  {isSupplierContext
                    ? t('status.noPurchaseOrders')
                    : t('status.noDocuments')}
                </h3>
                <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>
                  {isSupplierContext
                    ? t('status.noPurchaseOrdersDescription')
                    : t('status.noDocumentsDescription')}
                </p>
                <div className="mt-6 flex justify-center gap-3">
                  {isSupplierContext ? (
                    <Link
                      to={`/purchases/orders/new?supplier=${partner.id}`}
                      className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
                    >
                      <FileText className="h-4 w-4" />
                      {t('actions.newPurchaseOrder')}
                    </Link>
                  ) : (
                    <Link
                      to={`/sales/quotes/new?customer=${partner.id}`}
                      className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover}`}
                    >
                      <FileText className="h-4 w-4" />
                      {t('actions.newQuote')}
                    </Link>
                  )}
                </div>
              </div>
            ) : (
              <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base}`}>
                <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
                  <thead className={colorTokens.surface.page}>
                    <tr>
                      <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                        {t('fields.documentNumber')}
                      </th>
                      <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                        {t('fields.type')}
                      </th>
                      <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                        {t('fields.status')}
                      </th>
                      <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                        {t('fields.amount')}
                      </th>
                      <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                        {t('fields.date')}
                      </th>
                    </tr>
                  </thead>
                  <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
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
                        <tr key={doc.id} className={colorTokens.intent.neutral.bgHover}>
                          <td className="whitespace-nowrap px-6 py-4">
                            <Link
                              to={getDocumentPath()}
                              className={`font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrongest}`}
                            >
                              {getDocumentNumberLabel(doc.document_number)}
                            </Link>
                          </td>
                          <td className="whitespace-nowrap px-6 py-4">
                            <span
                              className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${documentTypeColors[doc.type]}`}
                            >
                              {t(`common:partner.documentType.${doc.type}`)}
                            </span>
                          </td>
                          <td className="whitespace-nowrap px-6 py-4">
                            <span
                              className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${documentWorkflowClasses[doc.status]}`}
                            >
                              {doc.status}
                            </span>
                          </td>
                          <td className={`whitespace-nowrap px-6 py-4 text-end text-sm font-medium ${colorTokens.text.primary}`}>
                            {formatAmount(doc.total ?? '0')}
                          </td>
                          <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                            {new Date(doc.document_date).toLocaleDateString()}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </DataTable>
                {documentsMeta?.current_page && documentsMeta.last_page && documentsMeta.last_page > 1 && (
                  <OffsetPagination
                    currentPage={documentsMeta.current_page}
                    lastPage={documentsMeta.last_page}
                    total={documentsMeta.total ?? documents.length}
                    perPage={documentsMeta.per_page ?? documentsPerPage}
                    from={documentsMeta.from ?? null}
                    to={documentsMeta.to ?? null}
                    onPageChange={setDocumentsPage}
                    onPerPageChange={(nextPerPage) => {
                      setDocumentsPerPage(nextPerPage)
                      setDocumentsPage(1)
                    }}
                  />
                )}
              </div>
            )}
          </TabsContent>
        )}

        {/* Payments Tab */}
        <TabsContent value="payments" className="mt-6">
          {payments.length === 0 ? (
            <div className={`rounded-lg border-2 border-dashed ${colorTokens.border.default} p-12 text-center`}>
              <CreditCard className={`mx-auto h-12 w-12 ${colorTokens.text.disabled}`} />
              <h3 className={`mt-2 text-sm font-semibold ${colorTokens.text.primary}`}>
                {t('status.noPayments')}
              </h3>
              <p className={`mt-1 text-sm ${colorTokens.text.subtle}`}>{t('status.noPaymentsDescription')}</p>
            </div>
          ) : (
            <div className={`overflow-hidden rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base}`}>
              <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
                <thead className={colorTokens.surface.page}>
                  <tr>
                    <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                      {t('fields.paymentNumber')}
                    </th>
                    <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                      {t('fields.method')}
                    </th>
                    <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                      {t('fields.status')}
                    </th>
                    <th className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                      {t('fields.amount')}
                    </th>
                    <th className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${colorTokens.text.subtle}`}>
                      {t('fields.date')}
                    </th>
                  </tr>
                </thead>
                <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                  {payments.map((payment) => (
                    <tr key={payment.id} className={colorTokens.intent.neutral.bgHover}>
                      <td className="whitespace-nowrap px-6 py-4">
                        <Link
                          to={`/treasury/payments/${payment.id}`}
                          className={`font-medium ${colorTokens.intent.primary.text} ${colorTokens.intent.primary.textHoverStrongest}`}
                        >
                          {payment.payment_number}
                        </Link>
                      </td>
                      <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                        {payment.payment_method_name ?? '-'}
                      </td>
                      <td className="whitespace-nowrap px-6 py-4">
                        <div className="flex items-center gap-2">
                          <span
                            className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${documentWorkflowClasses[payment.status] ?? `${colorTokens.surface.muted} ${colorTokens.text.strong}`}`}
                          >
                            {payment.status}
                          </span>
                          {payment.payment_type === 'advance' && (
                            <span className={`inline-flex rounded-full ${colorTokens.intent.primary.bgSoft} px-2.5 py-0.5 text-xs font-medium ${colorTokens.intent.primary.textStronger}`}>
                              {t('treasury:payments.types.advance')}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="whitespace-nowrap px-6 py-4 text-end">
                        <div className={`text-sm font-medium ${colorTokens.text.primary}`}>
                          {formatAmount(payment.amount)}
                        </div>
                        {bccomp(payment.unallocated_amount, '0') > 0 && (
                          <div className={`text-xs ${colorTokens.intent.primary.text}`}>
                            {t('treasury:payments.creditBalance')}: {formatAmount(payment.unallocated_amount)}
                          </div>
                        )}
                      </td>
                      <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorTokens.text.subtle}`}>
                        {new Date(payment.payment_date).toLocaleDateString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>
              {paymentsMeta?.current_page && paymentsMeta.last_page && paymentsMeta.last_page > 1 && (
                <OffsetPagination
                  currentPage={paymentsMeta.current_page}
                  lastPage={paymentsMeta.last_page}
                  total={paymentsMeta.total ?? payments.length}
                  perPage={paymentsMeta.per_page ?? paymentsPerPage}
                  from={paymentsMeta.from ?? null}
                  to={paymentsMeta.to ?? null}
                  onPageChange={setPaymentsPage}
                  onPerPageChange={(nextPerPage) => {
                    setPaymentsPerPage(nextPerPage)
                    setPaymentsPage(1)
                  }}
                />
              )}
            </div>
          )}
        </TabsContent>

        {/* Vehicles Tab */}
        {showVehiclesTab && (
          <TabsContent value="vehicles" className="mt-6">
            <div className="flex items-center justify-end mb-4">
              <button
                type="button"
                onClick={() => { setShowVehicleModal(true) }}
                className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
              >
                <Plus className="h-4 w-4" />
                {t('actions.addVehicle')}
              </button>
            </div>
            <VehiclesTab partnerId={id} />
          </TabsContent>
        )}

        {/* Deposits Tab */}
        {showDepositsTab && (
          <TabsContent value="deposits" className="mt-6">
            <div className="flex items-center justify-between mb-4">
              <h3 className={`text-sm font-medium ${colorTokens.text.primary}`}>{t('deposits:history.title')}</h3>
              <button
                type="button"
                onClick={() => { setShowDepositModal(true); }}
                className={`inline-flex items-center gap-2 rounded-lg border ${colorTokens.border.default} ${colorTokens.surface.base} px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.intent.neutral.bgHover} transition-colors`}
              >
                <Wallet className="h-4 w-4" />
                {t('deposits:recordButton')}
              </button>
            </div>
            {deposits.length === 0 ? (
              <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} px-4 py-8 text-center text-sm ${colorTokens.text.subtle}`}>
                {t('deposits:history.empty')}
              </div>
            ) : (
              <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
                <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
                  <thead className={colorTokens.surface.page}>
                    <tr>
                      <th className={`px-4 py-2 text-left text-xs font-medium uppercase ${colorTokens.text.subtle}`}>{t('deposits:history.date')}</th>
                      <th className={`px-4 py-2 text-left text-xs font-medium uppercase ${colorTokens.text.subtle}`}>{t('deposits:history.amount')}</th>
                      <th className={`px-4 py-2 text-left text-xs font-medium uppercase ${colorTokens.text.subtle}`}>{t('deposits:history.method')}</th>
                      <th className={`px-4 py-2 text-left text-xs font-medium uppercase ${colorTokens.text.subtle}`}>{t('deposits:history.note')}</th>
                      <th className={`px-4 py-2 text-left text-xs font-medium uppercase ${colorTokens.text.subtle}`}>{t('deposits:history.actor')}</th>
                    </tr>
                  </thead>
                  <tbody className={`divide-y ${colorTokens.border.divider} ${colorTokens.surface.base}`}>
                    {deposits.map((deposit) => (
                      <tr key={deposit.fiscal_event_id}>
                        <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>
                          {new Date(deposit.recorded_at).toLocaleDateString()}
                        </td>
                        <td className={`px-4 py-2 text-sm font-medium ${colorTokens.text.primary}`}>
                          {formatCurrency(deposit.amount, { currency: companyCurrency, locale: companyLocale })}
                        </td>
                        <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>{deposit.payment_method_code}</td>
                        <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>{deposit.note}</td>
                        <td className={`px-4 py-2 text-sm ${colorTokens.text.secondary}`}>{deposit.actor_name}</td>
                      </tr>
                    ))}
                  </tbody>
                </DataTable>
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
          void queryClient.invalidateQueries({
            predicate: partnerVehiclesInvalidationPredicate(id, tenantId, companyId),
          })
        }}
      />

      {/* Record Deposit Modal */}
      <RecordDepositModal
        isOpen={showDepositModal}
        onClose={() => { setShowDepositModal(false); }}
        partnerId={partner.id}
      />

      {/* Delete Confirmation Dialog */}
      <ConfirmDialog
        isOpen={showDeleteDialog}
        onClose={() => { setShowDeleteDialog(false) }}
        onConfirm={() => {
          setShowDeleteDialog(false)
          deleteMutation.mutate()
        }}
        title={t('sales:partners.delete.title', { entity: entityLabel })}
        message={t('sales:partners.delete.confirm', { name: partner.name })}
        confirmText={t('actions.delete')}
        variant="danger"
        isLoading={deleteMutation.isPending}
      />
    </div>
  )
}
