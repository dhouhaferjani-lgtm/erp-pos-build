import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import {
  Package,
  CheckCircle2,
  Clock,
  AlertCircle,
  ChevronRight,
  Building2,
  Calendar,
  Plus,
  Truck,
  ReceiptText,
  Printer,
  ScanLine,
  Trash2,
} from 'lucide-react'
import { toast } from 'sonner'
import { api, getErrorMessage } from '../../lib/api'
import { formatDate as formatLocaleDate } from '../../lib/format'
import { textColors, tokens } from '../../lib/designTokens'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useCompany } from '../../hooks/useCompany'
import { usePermissions } from '../../hooks/usePermissions'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { Button } from '../../components/atoms/Button/Button'
import { EntityLink } from '../../components/molecules/EntityLink'
import { PageHeader } from '../../components/molecules/PageHeader/PageHeader'
import { ReceiveGoodsDialog, type ReceiveGoodsRequest } from './components/ReceiveGoodsDialog'

interface PurchaseOrderLine {
  id: string
  product_id: string | null
  product_name: string | null
  description: string
  quantity: number
  quantity_received: number
  free_quantity?: string | number | null
  free_quantity_received?: string | number | null
  quantity_decimals?: number
  requires_batch_tracking?: boolean
  unit_price: number
}

interface PurchaseOrder {
  id: string
  document_number: string
  partner_id: string
  partner_name: string
  status: string
  issue_date?: string | null
  document_date?: string | null
  total: number
  currency: string
  lines: PurchaseOrderLine[]
  payload?: {
    goods_received?: boolean
    fully_received?: boolean
    last_goods_receipt_at?: string
  }
}

interface ApiResponse {
  data: PurchaseOrder[]
  meta?: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

interface DetailApiResponse {
  data: PurchaseOrder
}

interface ReceiveGoodsResponse {
  data: PurchaseOrder
  meta?: {
    goods_receipt?: {
      receipt_number?: string | null
      status?: string | null
    } | null
  }
}

interface GoodsReceiptIndexResponse {
  data: GoodsReceiptSummary[]
  meta?: {
    total?: number
  }
}

interface GoodsReceiptSummary {
  id: string
  purchase_order_id: string
  purchase_order_number?: string | null
  supplier_id?: string | null
  supplier_name?: string | null
  receipt_number?: string | null
  status?: string | null
  external_reference?: string | null
  received_at?: string | null
  created_at?: string | null
  lines_count?: number | null
  lines_summary?: string | null
  lines?: readonly unknown[]
}

type TabType = 'pending' | 'received' | 'drafts'

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      Array.isArray(k) &&
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function GoodsReceiptListPage() {
  const { t } = useTranslation(['common', 'sales', 'inventory', 'purchases', 'documentIngestions'])
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { hasPermission } = usePermissions()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const hasTenantScope = tenantId !== null && companyId !== null
  const [activeTab, setActiveTab] = useState<TabType>('pending')
  const [selectedPO, setSelectedPO] = useState<PurchaseOrder | null>(null)
  const [selectedInvoicePoIds, setSelectedInvoicePoIds] = useState<string[]>([])
  const [showReceiveModal, setShowReceiveModal] = useState(false)
  const [isLoadingReceiveDetail, setIsLoadingReceiveDetail] = useState(false)

  // Fetch confirmed purchase orders (pending receipt)
  const { data: pendingData, isLoading: pendingLoading } = useQuery({
    queryKey: tenantScopedKey(['purchase-orders', 'pending-receipt']),
    queryFn: async () => {
      const response = await api.get<ApiResponse>('/purchase-orders', {
        params: { status: 'confirmed', per_page: 100 },
      })
      // Filter to only show orders that are not fully received
      const orders = response.data.data.filter(
        (po) => !po.payload?.fully_received
      )
      return orders
    },
    enabled: hasTenantScope,
  })

  // Fetch received purchase orders
  const { data: receivedData, isLoading: receivedLoading } = useQuery({
    queryKey: tenantScopedKey(['purchase-orders', 'received']),
    queryFn: async () => {
      const response = await api.get<ApiResponse>('/purchase-orders', {
        params: { status: 'received', per_page: 100 },
      })
      return response.data.data
    },
    enabled: hasTenantScope,
  })

  // Also include confirmed POs that are fully received
  const { data: fullyReceivedConfirmed } = useQuery({
    queryKey: tenantScopedKey(['purchase-orders', 'confirmed-fully-received']),
    queryFn: async () => {
      const response = await api.get<ApiResponse>('/purchase-orders', {
        params: { status: 'confirmed', per_page: 100 },
      })
      return response.data.data.filter((po) => po.payload?.fully_received)
    },
    enabled: hasTenantScope,
  })

  const { data: draftReceiptCount = 0 } = useQuery({
    queryKey: tenantScopedKey(['goods-receipts', 'draft-count']),
    queryFn: async () => {
      const response = await api.get<GoodsReceiptIndexResponse>('/goods-receipts', {
        params: { status: 'draft', per_page: 1 },
      })
      return response.data.meta?.total ?? response.data.data.length
    },
    enabled: hasTenantScope,
  })

  const { data: draftReceipts = [], isLoading: draftReceiptsLoading } = useQuery({
    queryKey: tenantScopedKey(['goods-receipts', 'drafts']),
    queryFn: async () => {
      const response = await api.get<GoodsReceiptIndexResponse>('/goods-receipts', {
        params: { status: 'draft', per_page: 100 },
      })
      return response.data.data
    },
    enabled: hasTenantScope,
  })

  const { data: postedReceipts = [] } = useQuery({
    queryKey: tenantScopedKey(['goods-receipts', 'posted-pdf-links']),
    queryFn: async () => {
      const response = await api.get<GoodsReceiptIndexResponse>('/goods-receipts', {
        params: { status: 'posted', per_page: 100 },
      })
      return response.data.data
    },
    enabled: hasTenantScope,
  })

  // Receive goods mutation
  const receiveGoodsMutation = useMutation({
    mutationFn: async ({ poId, request }: { poId: string; request: ReceiveGoodsRequest }) => {
      const response = await api.post<ReceiveGoodsResponse>(`/purchase-orders/${poId}/receive`, request)
      return response.data
    },
    onSuccess: async (response) => {
      const receiptNumber = response.meta?.goods_receipt?.receipt_number
      const receiptStatus = response.meta?.goods_receipt?.status
      toast.success(receiptStatus === 'draft'
        ? t('inventory:goodsReceipt.draftSaved')
        : receiptNumber
          ? t('inventory:goodsReceipt.successMessageWithReceipt', { receiptNumber })
          : t('inventory:goodsReceipt.successMessage'))
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('purchase-orders', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('stock-levels', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('goods-receipts', tenantId, companyId),
        }),
      ])
      setShowReceiveModal(false)
      setSelectedPO(null)
    },
    onError: (error: Error & { response?: { data?: { message?: string; error?: { message?: string } } } }) => {
      const message = error.response?.data?.error?.message
        ?? error.response?.data?.message
        ?? error.message
      toast.error(message)
    },
  })

  const downloadGoodsReceiptPdfMutation = useMutation({
    mutationFn: async (receipt: GoodsReceiptSummary) => {
      const response = await api.get(`/goods-receipts/${receipt.id}/pdf`, {
        responseType: 'blob',
      })
      const contentDisposition = response.headers?.['content-disposition']
      const filenameMatch = typeof contentDisposition === 'string'
        ? contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
        : null
      const filename = filenameMatch?.[1]?.replace(/['"]/g, '')
        ?? `${receipt.receipt_number ?? receipt.id}.pdf`
      const blob = new Blob([response.data], { type: 'application/pdf' })
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const postDraftReceiptMutation = useMutation({
    mutationFn: async (receiptId: string) => {
      await api.post(`/goods-receipts/${receiptId}/post`)
    },
    onSuccess: async () => {
      toast.success(t('inventory:goodsReceipt.messages.draftPosted'))
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('goods-receipts', tenantId, companyId),
        }),
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('purchase-orders', tenantId, companyId),
        }),
      ])
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const deleteDraftReceiptMutation = useMutation({
    mutationFn: async (receiptId: string) => {
      await api.delete(`/goods-receipts/${receiptId}`)
    },
    onSuccess: async () => {
      toast.success(t('inventory:goodsReceipt.messages.draftDeleted'))
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('goods-receipts', tenantId, companyId),
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const handleReceiveClick = (po: PurchaseOrder) => {
    setIsLoadingReceiveDetail(true)
    api.get<DetailApiResponse>(`/purchase-orders/${po.id}`)
      .then((response) => {
        setSelectedPO(response.data.data)
        setShowReceiveModal(true)
      })
      .catch(() => {
        toast.error(t('common:errors.loadingFailed'))
      })
      .finally(() => {
        setIsLoadingReceiveDetail(false)
      })
  }

  const handleConfirmReceive = (request: ReceiveGoodsRequest) => {
    if (selectedPO) {
      receiveGoodsMutation.mutate({ poId: selectedPO.id, request })
    }
  }

  const formatCurrency = (amount: number, currency: string) => {
    // Use document currency, fallback to company currency, then USD
    const currencyCode = currency || currentCompany?.currency || 'USD'
    const locale = currencyCode === 'TND' ? 'fr-TN' : currencyCode === 'EUR' ? 'fr-FR' : 'en-US'
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency: currencyCode,
    }).format(amount)
  }

  const formatDate = (dateString?: string | null) => {
    if (!dateString) {
      return t('sales:notApplicable')
    }

    const date = new Date(dateString)
    if (Number.isNaN(date.getTime())) {
      return t('sales:notApplicable')
    }

    return formatLocaleDate(date)
  }

  const calculateReceiptProgress = (po: PurchaseOrder): { received: number; total: number; percentage: number } => {
    let totalQty = 0
    let receivedQty = 0

    po.lines.forEach((line) => {
      if (line.product_id) {
        totalQty += line.quantity
        receivedQty += line.quantity_received || 0
      }
    })

    const percentage = totalQty > 0 ? Math.round((receivedQty / totalQty) * 100) : 0
    return { received: receivedQty, total: totalQty, percentage }
  }

  const pendingOrders = pendingData ?? []
  const receivedOrders = [...(receivedData ?? []), ...(fullyReceivedConfirmed ?? [])]
  const isLoading = activeTab === 'pending'
    ? pendingLoading
    : activeTab === 'received'
      ? receivedLoading
      : draftReceiptsLoading

  const orders = activeTab === 'pending' ? pendingOrders : receivedOrders
  const canCreateSupplierInvoice = hasPermission('purchases.create')
  const selectedInvoicePurchaseOrders = receivedOrders.filter((po) => selectedInvoicePoIds.includes(po.id))
  const selectedInvoiceSupplierIds = Array.from(new Set(selectedInvoicePurchaseOrders.map((po) => po.partner_id)))
  const crossSupplierInvoiceSelection = selectedInvoiceSupplierIds.length > 1
  const canInvoiceSelectedReceipts = activeTab === 'received' && selectedInvoicePoIds.length > 0 && !crossSupplierInvoiceSelection
  const invoiceSelectionBlocked = activeTab === 'received' && selectedInvoicePoIds.length > 0 && crossSupplierInvoiceSelection
  const postedReceiptByPurchaseOrder = new Map(postedReceipts.map((receipt) => [receipt.purchase_order_id, receipt]))

  function toggleInvoiceSelection(poId: string) {
    setSelectedInvoicePoIds((current) =>
      current.includes(poId)
        ? current.filter((id) => id !== poId)
        : [...current, poId],
    )
  }

  function handleInvoiceReceipts() {
    if (selectedInvoicePoIds.length > 0 && !crossSupplierInvoiceSelection) {
      const query = new URLSearchParams()
      selectedInvoicePoIds.forEach((poId) => { query.append('po', poId) })
      query.set('entry', 'receipts')
      void navigate(`/purchases/supplier-invoices/new?${query.toString()}`)
    }
  }

  function handleDeleteDraftReceipt(receiptId: string) {
    if (window.confirm(t('inventory:goodsReceipt.confirm.deleteDraft'))) {
      deleteDraftReceiptMutation.mutate(receiptId)
    }
  }

  function handlePostDraftReceipt(receiptId: string) {
    if (window.confirm(t('inventory:goodsReceipt.confirm.postDraft'))) {
      postDraftReceiptMutation.mutate(receiptId)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('inventory:goodsReceipt.title')}
        subtitle={t('inventory:goodsReceipt.description')}
        actions={
          <>
            {hasPermission('document-ingestions.view') && (
              <Button
                type="button"
                onClick={() => {
                  void navigate('/purchases/scans/new?kind=supplier_delivery_note')
                }}
                variant="secondary"
                className="gap-2"
              >
                <ScanLine className="h-4 w-4" />
                {t('documentIngestions:actions.scanDeliveryNote')}
              </Button>
            )}
            {hasPermission('goods-receipt.create-standalone') && (
              <Button
                type="button"
                onClick={() => {
                  void navigate('/purchases/receipts/new')
                }}
                variant="secondary"
                className="gap-2"
              >
                <Plus className="h-4 w-4" />
                {t('purchases:standaloneReceipt.actions.newReceipt')}
              </Button>
            )}
            {activeTab === 'received' && canCreateSupplierInvoice && (
              <div className="flex flex-col items-end gap-1">
                <Button
                  type="button"
                  data-testid="invoice-receipts"
                  disabled={!canInvoiceSelectedReceipts}
                  title={
                    invoiceSelectionBlocked
                      ? t('purchases:supplierInvoices.create.crossSupplierTooltip')
                      : undefined
                  }
                  onClick={handleInvoiceReceipts}
                  className="gap-2"
                >
                  <ReceiptText className="h-4 w-4" />
                  {t('purchases:supplierInvoices.create.invoiceReceipts')}
                </Button>
                {invoiceSelectionBlocked && (
                  <span className={`text-xs ${textColors.warning}`}>
                    {t('purchases:supplierInvoices.create.crossSupplierTooltip')}
                  </span>
                )}
              </div>
            )}
          </>
        }
        className="mb-0"
      />

      {/* Tabs */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex space-x-8">
          <button
            type="button"
            onClick={() => {
              setActiveTab('pending')
              setSelectedInvoicePoIds([])
            }}
            className={`flex items-center gap-2 border-b-2 px-1 py-4 text-sm font-medium transition-colors ${
              activeTab === 'pending'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
          >
            <Clock className="h-4 w-4" />
            {t('inventory:goodsReceipt.tabs.pending')}
            {pendingOrders.length > 0 && (
              <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                {pendingOrders.length}
              </span>
            )}
            {draftReceiptCount > 0 && (
              <span className={`${tokens.badge.base} ${tokens.badge.yellow}`}>
                {t('inventory:goodsReceipt.status.draft')} {draftReceiptCount}
              </span>
            )}
          </button>
          <button
            type="button"
            onClick={() => {
              setActiveTab('received')
              setSelectedInvoicePoIds([])
            }}
            className={`flex items-center gap-2 border-b-2 px-1 py-4 text-sm font-medium transition-colors ${
              activeTab === 'received'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
          >
            <CheckCircle2 className="h-4 w-4" />
            {t('inventory:goodsReceipt.tabs.received')}
          </button>
          <button
            type="button"
            onClick={() => {
              setActiveTab('drafts')
              setSelectedInvoicePoIds([])
            }}
            className={`flex items-center gap-2 border-b-2 px-1 py-4 text-sm font-medium transition-colors ${
              activeTab === 'drafts'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
          >
            <ReceiptText className="h-4 w-4" />
            {t('inventory:goodsReceipt.tabs.drafts')}
            {draftReceiptCount > 0 && (
              <span className={`${tokens.badge.base} ${tokens.badge.yellow}`}>
                {draftReceiptCount}
              </span>
            )}
          </button>
        </nav>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('common:status.loading')}</div>
        </div>
      ) : activeTab === 'drafts' ? (
        draftReceipts.length === 0 ? (
          <div className="rounded-lg border border-gray-200 bg-white p-12 text-center">
            <ReceiptText className="mx-auto h-12 w-12 text-gray-400" />
            <h3 className="mt-4 text-lg font-medium text-gray-900">
              {t('inventory:goodsReceipt.emptyDrafts')}
            </h3>
          </div>
        ) : (
          <div className="space-y-4">
            {draftReceipts.map((receipt) => (
              <div
                key={receipt.id}
                className="rounded-lg border border-gray-200 bg-white p-4 transition-colors hover:border-gray-300"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-3">
                      <span className="text-lg font-semibold text-gray-900">
                        {receipt.receipt_number ?? receipt.id}
                      </span>
                      <span className={`${tokens.badge.base} ${tokens.badge.yellow}`}>
                        {t('inventory:goodsReceipt.status.draft')}
                      </span>
                    </div>

                    <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-gray-500">
                      {receipt.supplier_id && receipt.supplier_name && (
                        <span className="flex items-center gap-1">
                          <Building2 className="h-4 w-4" />
                          <EntityLink
                            type="supplier"
                            id={receipt.supplier_id}
                            label={receipt.supplier_name}
                            className="text-sm"
                          />
                        </span>
                      )}
                      {receipt.purchase_order_id && (
                        <EntityLink
                          type="document"
                          id={receipt.purchase_order_id}
                          documentType="purchase_order"
                          label={receipt.purchase_order_number ?? receipt.purchase_order_id}
                          className="text-sm"
                        />
                      )}
                      <span className="flex items-center gap-1">
                        <Calendar className="h-4 w-4" />
                        {formatDate(receipt.created_at ?? receipt.received_at)}
                      </span>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-4 text-sm text-gray-600">
                      <span>
                        {receipt.lines_summary
                          ?? t('inventory:goodsReceipt.linesSummary', {
                            count: receipt.lines_count ?? receipt.lines?.length ?? 0,
                          })}
                      </span>
                      {receipt.external_reference && (
                        <span>
                          {receipt.external_reference}
                        </span>
                      )}
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => { handlePostDraftReceipt(receipt.id) }}
                      disabled={postDraftReceiptMutation.isPending && postDraftReceiptMutation.variables === receipt.id}
                      className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm} gap-1`}
                    >
                      <CheckCircle2 className="h-4 w-4" />
                      {t('inventory:goodsReceipt.actions.postDraft')}
                    </button>
                    <button
                      type="button"
                      onClick={() => { handleDeleteDraftReceipt(receipt.id) }}
                      disabled={deleteDraftReceiptMutation.isPending && deleteDraftReceiptMutation.variables === receipt.id}
                      className={`${tokens.button.base} ${tokens.button.danger} ${tokens.button.sizes.sm} gap-1`}
                    >
                      <Trash2 className="h-4 w-4" />
                      {t('inventory:goodsReceipt.actions.deleteDraft')}
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )
      ) : orders.length === 0 ? (
        <div className="rounded-lg border border-gray-200 bg-white p-12 text-center">
          <Package className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-4 text-lg font-medium text-gray-900">
            {activeTab === 'pending'
              ? t('inventory:goodsReceipt.emptyPending')
              : t('inventory:goodsReceipt.emptyReceived')}
          </h3>
          {activeTab === 'pending' && (
            <p className="mt-2 text-sm text-gray-500">
              {t('inventory:goodsReceipt.emptyPendingHint')}
            </p>
          )}
        </div>
      ) : (
        <div className="space-y-4">
          {orders.map((po) => {
            const progress = calculateReceiptProgress(po)
            const isFullyReceived = po.payload?.fully_received ?? (progress.percentage === 100)

            return (
              <div
                key={po.id}
                className="rounded-lg border border-gray-200 bg-white p-4 hover:border-gray-300 transition-colors"
              >
                <div className="flex items-start justify-between gap-4">
                  {/* Order Info */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-3">
                      {activeTab === 'received' && (
                        <input
                          type="checkbox"
                          aria-label={t('purchases:supplierInvoices.create.selectReceiptPo', { number: po.document_number })}
                          checked={selectedInvoicePoIds.includes(po.id)}
                          onChange={() => { toggleInvoiceSelection(po.id) }}
                          className={tokens.checkbox.base}
                        />
                      )}
                      <EntityLink
                        type="document"
                        id={po.id}
                        documentType="purchase_order"
                        label={po.document_number}
                        className="text-lg font-semibold text-gray-900 hover:text-blue-600"
                      />
                      {isFullyReceived ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-700">
                          <CheckCircle2 className="h-3 w-3" />
                          {t('inventory:goodsReceipt.status.received')}
                        </span>
                      ) : progress.percentage > 0 ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-700">
                          <AlertCircle className="h-3 w-3" />
                          {t('inventory:goodsReceipt.status.partial')}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                          <Clock className="h-3 w-3" />
                          {t('inventory:goodsReceipt.status.pending')}
                        </span>
                      )}
                    </div>

                    <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-gray-500">
                      <span className="flex items-center gap-1">
                        <Building2 className="h-4 w-4" />
                        <EntityLink
                          type="supplier"
                          id={po.partner_id}
                          label={po.partner_name}
                          className="text-sm"
                        />
                      </span>
                      <span className="flex items-center gap-1">
                        <Calendar className="h-4 w-4" />
                        {formatDate(po.issue_date ?? po.document_date)}
                      </span>
                      <span className="font-medium text-gray-900">
                        {formatCurrency(po.total, po.currency)}
                      </span>
                    </div>

                    {/* Progress bar for partial receipts */}
                    {activeTab === 'pending' && progress.total > 0 && (
                      <div className="mt-3">
                        <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                          <span>
                            {t('inventory:goodsReceipt.progress', {
                              received: progress.received,
                              total: progress.total,
                            })}
                          </span>
                          <span>{progress.percentage}%</span>
                        </div>
                        <div className="h-2 w-full rounded-full bg-gray-100 overflow-hidden">
                          <div
                            className={`h-full rounded-full transition-all ${
                              progress.percentage === 100
                                ? 'bg-green-500'
                                : progress.percentage > 0
                                ? 'bg-amber-500'
                                : 'bg-gray-300'
                            }`}
                            style={{ width: `${progress.percentage}%` }}
                          />
                        </div>
                      </div>
                    )}

                    {/* Lines preview */}
                    <div className="mt-3 text-sm text-gray-600">
                      {po.lines.slice(0, 3).map((line, idx) => (
                        <div key={line.id} className="flex items-center gap-2">
                          <span className="text-gray-400">{idx + 1}.</span>
                          <EntityLink
                            type="product"
                            id={line.product_id}
                            label={line.product_name ?? line.description}
                            className="truncate"
                          />
                          <span className="text-gray-400">
                            ({line.quantity_received ?? 0}/{line.quantity})
                          </span>
                        </div>
                      ))}
                      {po.lines.length > 3 && (
                        <span className="text-gray-400">
                          +{po.lines.length - 3} {t('common:more')}
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Actions */}
                  <div className="flex items-center gap-2">
                    {activeTab === 'received' && postedReceiptByPurchaseOrder.has(po.id) && (() => {
                      const receipt = postedReceiptByPurchaseOrder.get(po.id)
                      if (!receipt) return null
                      const receiptNumber = receipt.receipt_number ?? ''
                      return (
                        <button
                          type="button"
                          onClick={(e) => {
                            e.stopPropagation()
                            downloadGoodsReceiptPdfMutation.mutate(receipt)
                          }}
                          disabled={downloadGoodsReceiptPdfMutation.isPending}
                          aria-label={`${t('inventory:goodsReceipt.printGrn')} ${receiptNumber}`.trim()}
                          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm} gap-1`}
                        >
                          <Printer className="h-4 w-4" />
                          {t('inventory:goodsReceipt.printGrn')}
                        </button>
                      )
                    })()}
                    {activeTab === 'pending' && !isFullyReceived && (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          handleReceiveClick(po)
                        }}
                        disabled={isLoadingReceiveDetail}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-60 transition-colors"
                      >
                        <Truck className="h-4 w-4" />
                        {isLoadingReceiveDetail ? t('common:status.loading') : t('inventory:goodsReceipt.receiveAll')}
                      </button>
                    )}
                    <EntityLink
                      type="document"
                      id={po.id}
                      documentType="purchase_order"
                      label={(
                        <>
                          {t('common:actions.view')}
                          <ChevronRight className="h-4 w-4" />
                        </>
                      )}
                      className="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                    />
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {selectedPO && (
        <ReceiveGoodsDialog
          key={`${selectedPO.id}-${showReceiveModal ? 'open' : 'closed'}`}
          isOpen={showReceiveModal}
          purchaseOrder={selectedPO}
          isLoading={receiveGoodsMutation.isPending}
          onClose={() => {
            setShowReceiveModal(false)
            setSelectedPO(null)
          }}
          onConfirm={handleConfirmReceive}
        />
      )}
    </div>
  )
}
