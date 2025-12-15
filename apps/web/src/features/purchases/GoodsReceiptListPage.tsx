import { useState } from 'react'
import { Link } from 'react-router-dom'
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
  Truck
} from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost } from '../../lib/api'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { useCompany } from '../../hooks/useCompany'

interface PurchaseOrderLine {
  id: string
  product_id: string | null
  product_name: string | null
  description: string
  quantity: number
  quantity_received: number
  unit_price: number
}

interface PurchaseOrder {
  id: string
  document_number: string
  partner_id: string
  partner_name: string
  status: string
  issue_date: string
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

type TabType = 'pending' | 'received'

export function GoodsReceiptListPage() {
  const { t } = useTranslation(['common', 'sales', 'inventory'])
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const [activeTab, setActiveTab] = useState<TabType>('pending')
  const [selectedPO, setSelectedPO] = useState<PurchaseOrder | null>(null)
  const [showReceiveModal, setShowReceiveModal] = useState(false)

  // Fetch confirmed purchase orders (pending receipt)
  const { data: pendingData, isLoading: pendingLoading } = useQuery({
    queryKey: ['purchase-orders', 'pending-receipt'],
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
  })

  // Fetch received purchase orders
  const { data: receivedData, isLoading: receivedLoading } = useQuery({
    queryKey: ['purchase-orders', 'received'],
    queryFn: async () => {
      const response = await api.get<ApiResponse>('/purchase-orders', {
        params: { status: 'received', per_page: 100 },
      })
      return response.data.data
    },
  })

  // Also include confirmed POs that are fully received
  const { data: fullyReceivedConfirmed } = useQuery({
    queryKey: ['purchase-orders', 'confirmed-fully-received'],
    queryFn: async () => {
      const response = await api.get<ApiResponse>('/purchase-orders', {
        params: { status: 'confirmed', per_page: 100 },
      })
      return response.data.data.filter((po) => po.payload?.fully_received)
    },
  })

  // Receive goods mutation
  const receiveGoodsMutation = useMutation({
    mutationFn: (poId: string) =>
      apiPost<{ message: string }>(`/purchase-orders/${poId}/receive`, {}),
    onSuccess: () => {
      toast.success(t('inventory:goodsReceipt.successMessage'))
      void queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      void queryClient.invalidateQueries({ queryKey: ['stock-levels'] })
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

  const handleReceiveClick = (po: PurchaseOrder) => {
    setSelectedPO(po)
    setShowReceiveModal(true)
  }

  const handleConfirmReceive = () => {
    if (selectedPO) {
      receiveGoodsMutation.mutate(selectedPO.id)
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

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString()
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
  const isLoading = activeTab === 'pending' ? pendingLoading : receivedLoading

  const orders = activeTab === 'pending' ? pendingOrders : receivedOrders

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">
            {t('inventory:goodsReceipt.title')}
          </h1>
          <p className="mt-1 text-sm text-gray-500">
            {t('inventory:goodsReceipt.description')}
          </p>
        </div>
      </div>

      {/* Tabs */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex space-x-8">
          <button
            type="button"
            onClick={() => { setActiveTab('pending') }}
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
          </button>
          <button
            type="button"
            onClick={() => { setActiveTab('received') }}
            className={`flex items-center gap-2 border-b-2 px-1 py-4 text-sm font-medium transition-colors ${
              activeTab === 'received'
                ? 'border-blue-500 text-blue-600'
                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
            }`}
          >
            <CheckCircle2 className="h-4 w-4" />
            {t('inventory:goodsReceipt.tabs.received')}
          </button>
        </nav>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('common:status.loading')}</div>
        </div>
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
                      <Link
                        to={`/purchases/orders/${po.id}`}
                        className="text-lg font-semibold text-gray-900 hover:text-blue-600"
                      >
                        {po.document_number}
                      </Link>
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
                        {po.partner_name}
                      </span>
                      <span className="flex items-center gap-1">
                        <Calendar className="h-4 w-4" />
                        {formatDate(po.issue_date)}
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
                          <span className="truncate">
                            {line.product_name ?? line.description}
                          </span>
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
                    {activeTab === 'pending' && !isFullyReceived && (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          handleReceiveClick(po)
                        }}
                        className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 transition-colors"
                      >
                        <Truck className="h-4 w-4" />
                        {t('inventory:goodsReceipt.receiveAll')}
                      </button>
                    )}
                    <Link
                      to={`/purchases/orders/${po.id}`}
                      className="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                    >
                      {t('common:actions.view')}
                      <ChevronRight className="h-4 w-4" />
                    </Link>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      )}

      {/* Receive Confirmation Modal */}
      <ConfirmDialog
        isOpen={showReceiveModal}
        onClose={() => {
          setShowReceiveModal(false)
          setSelectedPO(null)
        }}
        onConfirm={handleConfirmReceive}
        title={t('sales:purchaseOrders.receiveGoodsConfirm.title')}
        message={t('sales:purchaseOrders.receiveGoodsConfirm.message')}
        confirmText={t('sales:purchaseOrders.receiveGoodsConfirm.button')}
        variant="info"
        isLoading={receiveGoodsMutation.isPending}
      />
    </div>
  )
}
