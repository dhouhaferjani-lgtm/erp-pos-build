import { useState, useMemo } from 'react'
import { Link, useParams, useLocation, useNavigate } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { ArrowLeft, Edit, Calendar, Building2, FileText, Check, X, ArrowRight, Printer, Send, CreditCard, MinusCircle, Package, AlertTriangle, Truck, Link2, Paperclip, Download, Eye, Car } from 'lucide-react'
import { api, apiPost, getErrorMessage } from '../../lib/api'
import { formatCurrency } from '../../lib/format'
import { ConfirmDialog } from '../../components/ui/ConfirmDialog'
import { RecordPaymentModal, Modal } from '../../components/organisms'
import { PurchaseOrderLandedCostBreakdown } from './components/PurchaseOrderLandedCostBreakdown'
import { CreateCreditNoteForm, CreditNoteList, CreditNoteDetail, ReturnNoteMetadata } from './components'
import { CreateReturnNoteForm } from './components/CreateReturnNoteForm'
import { RelatedDocumentsTab } from './components/RelatedDocumentsTab'
import { DocumentAttachments } from './components/DocumentAttachments'
import { useCreditNotes, useCreditNote, useDownloadPdf, usePreviewPdf, usePrintPdf, useSendDocumentEmail } from './hooks'
import { useCompany } from '../../hooks/useCompany'
import type { DocumentType } from './DocumentListPage'
import type { CreditNote, DocumentStatus } from '../../types/creditNote'
import type { Document } from '../../types/document'

type ConfirmAction = 'confirm' | 'cancel' | 'convert' | 'convertToDelivery' | 'post' | 'receiveGoods' | null

const typeColors: Record<string, string> = {
  quote: 'bg-yellow-100 text-yellow-800',
  order: 'bg-blue-100 text-blue-800',
  sales_order: 'bg-blue-100 text-blue-800',
  purchase_order: 'bg-purple-100 text-purple-800',
  invoice: 'bg-green-100 text-green-800',
  credit_note: 'bg-red-100 text-red-800',
  delivery_note: 'bg-purple-100 text-purple-800',
  return_note: 'bg-orange-100 text-orange-800',
}

const statusColors: Record<Document['status'], string> = {
  draft: 'bg-gray-100 text-gray-800',
  confirmed: 'bg-blue-100 text-blue-800',
  posted: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
}

const documentTypeToPath: Record<DocumentType, string> = {
  quote: '/sales/quotes',
  sales_order: '/sales/orders',
  invoice: '/sales/invoices',
  purchase_order: '/purchases/orders',
  delivery_note: '/inventory/delivery-notes',
  credit_note: '/sales/credit-notes',
  return_note: '/inventory/return-notes',
}

// Map document types to their API endpoints
const documentTypeToApiEndpoint: Record<DocumentType, string> = {
  quote: '/quotes',
  sales_order: '/orders',
  invoice: '/invoices',
  purchase_order: '/purchase-orders',
  delivery_note: '/delivery-notes',
  credit_note: '/credit-notes',
  return_note: '/return-notes',
}

function getDocumentTypeFromPath(pathname: string): DocumentType | undefined {
  if (pathname.includes('/sales/quotes')) return 'quote'
  if (pathname.includes('/sales/orders')) return 'sales_order'
  if (pathname.includes('/sales/invoices')) return 'invoice'
  if (pathname.includes('/purchases/orders')) return 'purchase_order'
  if (pathname.includes('/inventory/delivery-notes')) return 'delivery_note'
  if (pathname.includes('/inventory/return-notes')) return 'return_note'
  if (pathname.includes('/sales/credit-notes')) return 'credit_note'
  return undefined
}

// Conversion targets by document type
const conversionTargets: Partial<Record<DocumentType, { label: string; targetType: DocumentType; path: string }>> = {
  quote: { label: 'Convert to Order', targetType: 'sales_order', path: '/sales/orders' },
  sales_order: { label: 'Convert to Invoice', targetType: 'invoice', path: '/sales/invoices' },
}

// Delivery note conversion (separate from invoice conversion)
const deliveryConversionTarget: { label: string; targetType: DocumentType; path: string } = {
  label: 'Convert to Delivery Note',
  targetType: 'delivery_note',
  path: '/inventory/delivery-notes',
}


export function DocumentDetailPage() {
  const { t } = useTranslation(['sales', 'common'])
  const { id = '' } = useParams<{ id: string }>()
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()

  // State for confirmation dialog
  const [confirmAction, setConfirmAction] = useState<ConfirmAction>(null)
  // State for payment modal
  const [showPaymentModal, setShowPaymentModal] = useState(false)
  // State for credit note modals
  const [showCreditNoteForm, setShowCreditNoteForm] = useState(false)
  const [selectedCreditNoteId, setSelectedCreditNoteId] = useState<string | null>(null)
  // State for return note modal
  const [showReturnNoteForm, setShowReturnNoteForm] = useState(false)
  // State for email modal
  const [showEmailModal, setShowEmailModal] = useState(false)
  const [emailForm, setEmailForm] = useState({
    recipientEmail: '',
    subject: '',
    message: '',
    ccEmails: '',
  })

  // Helper functions for translated labels
  const getTypeLabel = (type: string) => t(`documents.types.${type}`, type)
  const getStatusLabel = (status: string) => t(`documents.statuses.${status}`, status)

  // Determine document type from URL path first
  const contextType = getDocumentTypeFromPath(location.pathname)
  const apiEndpoint = contextType != null ? documentTypeToApiEndpoint[contextType] : null

  const { data: document, isLoading, error } = useQuery({
    queryKey: ['document', contextType, id],
    queryFn: async () => {
      if (apiEndpoint == null) throw new Error('No API endpoint')
      const response = await api.get<{ data: Document }>(`${apiEndpoint}/${id}`)
      return response.data.data
    },
    enabled: id.length > 0 && apiEndpoint != null,
  })

  // Fetch credit notes for invoices
  const { data: creditNotesData } = useCreditNotes(
    document?.type === 'invoice' && document.status === 'posted' ? { source_invoice_id: id } : undefined
  )

  const creditNotes = creditNotesData ?? []

  // Fetch selected credit note details
  const { data: selectedCreditNote } = useCreditNote(selectedCreditNoteId ?? undefined)

  // PDF mutations
  const downloadPdfMutation = useDownloadPdf()
  const previewPdfMutation = usePreviewPdf()
  const printPdfMutation = usePrintPdf()

  // Email mutation
  const sendEmailMutation = useSendDocumentEmail()

  // Determine effective type and paths
  const effectiveType = contextType ?? (document?.type as DocumentType | undefined)
  const effectiveApiEndpoint = effectiveType != null ? documentTypeToApiEndpoint[effectiveType] : '/documents'
  const basePath = effectiveType != null ? documentTypeToPath[effectiveType] : '/documents'

  // Status transition mutations
  const confirmMutation = useMutation({
    mutationFn: () => apiPost<Document>(`${effectiveApiEndpoint}/${id}/confirm`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.confirmed'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const cancelMutation = useMutation({
    mutationFn: () => apiPost<Document>(`${effectiveApiEndpoint}/${id}/cancel`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.cancelled'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const postMutation = useMutation({
    mutationFn: () => apiPost<Document>(`${effectiveApiEndpoint}/${id}/post`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      toast.success(t('documents.messages.posted'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const convertMutation = useMutation({
    mutationFn: (targetType: DocumentType) =>
      apiPost<Document>(`${effectiveApiEndpoint}/${id}/convert-to-${targetType === 'sales_order' ? 'order' : 'invoice'}`, {}),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
      const target = conversionTargets[document?.type as DocumentType]
      if (target != null && data?.id) {
        void navigate(`${target.path}/${data.id}`)
      }
    },
    onError: (error: Error) => {
      // Show error message to user
      toast.error(error.message || t('documents.conversionError'))
      // Refresh to get the latest state (in case it was already converted)
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
    },
  })

  // Convert sales order to delivery note
  const convertToDeliveryMutation = useMutation({
    mutationFn: () =>
      apiPost<Document>(`/orders/${id}/convert-to-delivery`, {}),
    onSuccess: (data) => {
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
      if (data?.id) {
        void navigate(`${deliveryConversionTarget.path}/${data.id}`)
      }
    },
    onError: (error: Error) => {
      // Show error message to user
      toast.error(error.message || t('documents.conversionError'))
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
    },
  })

  // Receive goods for purchase order
  const receiveGoodsMutation = useMutation({
    mutationFn: () =>
      apiPost<{ message: string }>(`/purchase-orders/${id}/receive`, {}),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['documents'] })
      void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
      void queryClient.invalidateQueries({ queryKey: ['stock-levels'] })
      toast.success(t('documents.messages.goodsReceived'))
    },
    onError: (error) => {
      toast.error(getErrorMessage(error))
    },
  })

  const isActionPending =
    confirmMutation.isPending ||
    cancelMutation.isPending ||
    postMutation.isPending ||
    convertMutation.isPending ||
    convertToDeliveryMutation.isPending ||
    receiveGoodsMutation.isPending

  // Get company currency with fallback
  const companyCurrency = currentCompany?.currency ?? 'EUR'
  const companyLocale = currentCompany?.locale?.replace('_', '-') ?? 'en-US'

  // Format currency using company settings
  const formatAmount = (amount: string | number) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    if (isNaN(num)) {
      return formatCurrency(0, { currency: companyCurrency, locale: companyLocale })
    }
    return formatCurrency(num, {
      currency: companyCurrency,
      locale: companyLocale,
    })
  }

  // Calculate quote expiry status - must be before early returns (React hooks rule)
  const quoteExpiryInfo = useMemo(() => {
    if (!document || document.type !== 'quote' || document.status === 'cancelled') {
      return null
    }

    const validUntil = document.valid_until
    if (!validUntil) return null

    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const expiryDate = new Date(validUntil)
    expiryDate.setHours(0, 0, 0, 0)

    const diffTime = expiryDate.getTime() - today.getTime()
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))

    if (diffDays < 0) {
      return { status: 'expired' as const, days: Math.abs(diffDays), message: 'expired' }
    } else if (diffDays === 0) {
      return { status: 'warning' as const, days: 0, message: 'expiresToday' }
    } else if (diffDays === 1) {
      return { status: 'warning' as const, days: 1, message: 'expiresTomorrow' }
    } else if (diffDays <= 7) {
      return { status: 'warning' as const, days: diffDays, message: 'expiresIn' }
    }
    return null
  }, [document])

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
          {t('common:errors.documentNotFound')}
        </div>
      </div>
    )
  }

  if (!document) {
    return null
  }

  // Recalculate basePath now that we have the document
  const finalBasePath = documentTypeToPath[document.type as DocumentType]
  const conversionTarget = conversionTargets[document.type as DocumentType]

  // Determine available actions based on status and type
  const isAlreadyConverted = document.converted_to_order_id != null
  const canEdit = document.status === 'draft' && !isAlreadyConverted
  const canConfirm = document.status === 'draft' && !isAlreadyConverted
  const canCancel = (document.status === 'draft' || document.status === 'confirmed') && !isAlreadyConverted
  const canPost = document.status === 'confirmed' && (document.type === 'invoice' || document.type === 'credit_note')
  // Only allow conversion from confirmed status (not draft)
  // Also check that sales orders are not already fully invoiced
  const canConvert = document.status === 'confirmed' &&
    conversionTarget != null &&
    !isAlreadyConverted &&
    !(document.type === 'sales_order' && document.fully_invoiced)
  // Use fully_delivered flag to determine if delivery note can be created
  const canConvertToDelivery = document.type === 'sales_order' && document.status === 'confirmed' && !document.fully_delivered
  const canReceiveGoods = document.type === 'purchase_order' && document.status === 'confirmed' && !document.goods_received
  const canRecordPayment =
    (document.type === 'invoice' && document.status === 'posted') ||
    (document.type === 'sales_order' && document.status === 'confirmed')

  // Check if this document has a source document to link back to
  const hasSourceDocument = document.source_document_id != null && document.source_document_number != null

  // Path for source document navigation
  const getSourceDocumentPath = () => {
    if (!document.source_document_type) return null
    const paths: Record<string, string> = {
      sales_order: '/sales/orders',
      quote: '/sales/quotes',
      invoice: '/sales/invoices',
      purchase_order: '/purchases/orders',
    }
    return paths[document.source_document_type] ?? null
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to={finalBasePath}
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold text-gray-900">
                {document.document_number}
              </h1>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${typeColors[document.type]}`}
              >
                {getTypeLabel(document.type)}
              </span>
              <span
                className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${statusColors[document.status]}`}
              >
                {getStatusLabel(document.status)}
              </span>
              {isAlreadyConverted && document.converted_to_order_id != null && (
                <Link
                  to={`/sales/orders/${document.converted_to_order_id}`}
                  className="inline-flex items-center gap-1.5 rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800 hover:bg-purple-200 transition-colors"
                >
                  <ArrowRight className="h-3 w-3" />
                  {t('documents.convertedToOrder')}
                </Link>
              )}
              {/* Show link back to source order for delivery notes and invoices */}
              {hasSourceDocument && getSourceDocumentPath() && (
                <Link
                  to={`${getSourceDocumentPath()}/${document.source_document_id}`}
                  className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800 hover:bg-gray-200 transition-colors"
                >
                  <ArrowLeft className="h-3 w-3" />
                  {document.source_document_number}
                </Link>
              )}
              {/* Show "Fully Delivered" badge for sales orders */}
              {document.type === 'sales_order' && document.fully_delivered && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                  <Truck className="h-3 w-3" />
                  {t('orders.fullyDelivered', 'Fully Delivered')}
                </span>
              )}
              {/* Show "Fully Invoiced" badge for sales orders */}
              {document.type === 'sales_order' && document.fully_invoiced && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                  <Check className="h-3 w-3" />
                  {t('orders.fullyInvoiced', 'Fully Invoiced')}
                </span>
              )}
              {document.goods_received && (
                <span className="inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                  <Package className="h-3 w-3" />
                  {t('purchaseOrders.goodsReceived', 'Goods Received')}
                </span>
              )}
              {quoteExpiryInfo && (
                <span
                  className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${
                    quoteExpiryInfo.status === 'expired'
                      ? 'bg-red-100 text-red-800'
                      : 'bg-yellow-100 text-yellow-800'
                  }`}
                >
                  <AlertTriangle className="h-3 w-3" />
                  {quoteExpiryInfo.message === 'expiresIn'
                    ? t('quotes.expiry.expiresIn', { days: quoteExpiryInfo.days })
                    : t(`quotes.expiry.${quoteExpiryInfo.message}`)}
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-2">
          {/* Download PDF button */}
          <button
            type="button"
            disabled={downloadPdfMutation.isPending}
            onClick={() => {
              downloadPdfMutation.mutate(id, {
                onSuccess: () => {
                  toast.success(t('common:actions.downloadPdf'))
                },
                onError: () => {
                  toast.error(t('common:error.generic'))
                },
              })
            }}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 transition-colors"
            title={t('common:actions.downloadPdf')}
          >
            <Download className="h-4 w-4" />
          </button>

          {/* Preview PDF button */}
          <button
            type="button"
            disabled={previewPdfMutation.isPending}
            onClick={() => {
              previewPdfMutation.mutate(id, {
                onError: () => {
                  toast.error(t('common:error.generic'))
                },
              })
            }}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 transition-colors"
            title={t('common:actions.previewPdf')}
          >
            <Eye className="h-4 w-4" />
          </button>

          {/* Print button */}
          <button
            type="button"
            disabled={printPdfMutation.isPending}
            onClick={() => {
              printPdfMutation.mutate(id, {
                onError: () => {
                  toast.error(t('common:error.generic'))
                },
              })
            }}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 transition-colors"
            title={t('common:actions.printPdf')}
          >
            <Printer className="h-4 w-4" />
          </button>

          {/* Send Email button - always available */}
          <button
            type="button"
            disabled={sendEmailMutation.isPending}
            onClick={() => {
              // Pre-fill with partner email if available
              setEmailForm({
                recipientEmail: document.partner_email ?? '',
                subject: '',
                message: '',
                ccEmails: '',
              })
              setShowEmailModal(true)
            }}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 transition-colors"
            title={t('common:actions.sendEmail')}
          >
            <Send className="h-4 w-4" />
          </button>

          {/* Cancel button */}
          {canCancel && (
            <button
              type="button"
              disabled={isActionPending}
              onClick={() => {
                setConfirmAction('cancel')
              }}
              className="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50 transition-colors"
            >
              <X className="h-4 w-4" />
              {t('documents.cancel')}
            </button>
          )}

          {/* Edit button */}
          {canEdit && (
            <Link
              to={`${finalBasePath}/${document.id}/edit`}
              className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
            >
              <Edit className="h-4 w-4" />
              {t('actions.edit')}
            </Link>
          )}

          {/* Confirm button */}
          {canConfirm && (
            <button
              type="button"
              disabled={isActionPending}
              onClick={() => {
                setConfirmAction('confirm')
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >
              <Check className="h-4 w-4" />
              {t('actions.confirm')}
            </button>
          )}

          {/* Post button - for invoices and credit notes */}
          {canPost && (
            <button
              type="button"
              disabled={isActionPending}
              onClick={() => {
                setConfirmAction('post')
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 transition-colors"
            >
              <Check className="h-4 w-4" />
              {t('documents.post')}
            </button>
          )}

          {/* Convert button */}
          {conversionTarget != null && canConvert && (
            <button
              type="button"
              disabled={isActionPending}
              onClick={() => {
                setConfirmAction('convert')
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50 transition-colors"
            >
              <ArrowRight className="h-4 w-4" />
              {document.type === 'quote' ? t('quotes.convertToOrder') : t('orders.convertToInvoice')}
            </button>
          )}

          {/* Convert to Delivery Note button - for sales orders */}
          {canConvertToDelivery && (
            <button
              type="button"
              disabled={isActionPending}
              onClick={() => {
                setConfirmAction('convertToDelivery')
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 transition-colors"
            >
              <Truck className="h-4 w-4" />
              {t('orders.convertToDelivery')}
            </button>
          )}

          {/* Receive Goods button - for confirmed purchase orders */}
          {canReceiveGoods && (
            <button
              type="button"
              disabled={isActionPending}
              onClick={() => {
                setConfirmAction('receiveGoods')
              }}
              className="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 disabled:opacity-50 transition-colors"
            >
              <Package className="h-4 w-4" />
              {t('purchaseOrders.receiveGoods')}
            </button>
          )}

          {/* Record Payment button - for posted invoices */}
          {canRecordPayment && (
            <button
              type="button"
              onClick={() => { setShowPaymentModal(true) }}
              className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 transition-colors"
            >
              <CreditCard className="h-4 w-4" />
              {t('documents.recordPayment')}
            </button>
          )}

          {/* Create Credit Note button - for posted invoices */}
          {document.type === 'invoice' && document.status === 'posted' && (
            <>
              <button
                type="button"
                onClick={() => { setShowCreditNoteForm(true) }}
                className="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 transition-colors"
              >
                <MinusCircle className="h-4 w-4" />
                {t('documents.createCreditNote')}
              </button>
              <Link
                to={`/sales/credit-notes/create?invoice_id=${document.id}`}
                className="inline-flex items-center gap-2 rounded-lg border border-red-600 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50 transition-colors"
              >
                <FileText className="h-4 w-4" />
                {t('sales:creditNotes.createFromInvoice')}
              </Link>
            </>
          )}

          {/* Create Return Note button - for posted invoices */}
          {document.type === 'invoice' && document.status === 'posted' && (
            <button
              type="button"
              onClick={() => { setShowReturnNoteForm(true) }}
              className="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700 transition-colors"
            >
              <Package className="h-4 w-4" />
              {t('returnNotes.createFromInvoice', 'Create Return Note')}
            </button>
          )}

          {/* Create Return Note button - for confirmed delivery notes */}
          {document.type === 'delivery_note' && document.status === 'confirmed' && (
            <button
              type="button"
              onClick={() => { setShowReturnNoteForm(true) }}
              className="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700 transition-colors"
            >
              <Package className="h-4 w-4" />
              {t('returnNotes.createFromDelivery', 'Create Return Note')}
            </button>
          )}
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Partner Info */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">
            {t('documents.partnerInfo')}
          </h2>
          <dl className="space-y-3">
            <div className="flex items-start gap-3">
              <Building2 className="mt-0.5 h-5 w-5 text-gray-400" />
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('documents.partner')}</dt>
                <dd>
                  {document.partner_id ? (
                    <Link
                      to={document.type === 'purchase_order'
                        ? `/purchases/suppliers/${document.partner_id}`
                        : `/sales/customers/${document.partner_id}`
                      }
                      className="text-blue-600 hover:text-blue-800 hover:underline font-medium"
                    >
                      {document.partner_name ?? t('common:status.unknown')}
                    </Link>
                  ) : (
                    <span className="text-gray-900">{document.partner_name ?? t('common:status.unknown')}</span>
                  )}
                </dd>
              </div>
            </div>
            {document.partner_email && (
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('documents.email')}</dt>
                <dd className="text-gray-900">{document.partner_email}</dd>
              </div>
            )}
            {document.vehicle_context && (
              <div className="flex items-start gap-3">
                <Car className="mt-0.5 h-5 w-5 text-gray-400" />
                <div className="flex-1">
                  <dt className="text-sm font-medium text-gray-500">{t('documents.vehicle', 'Vehicle')}</dt>
                  <dd>
                    {document.vehicle_context.vehicle_id ? (
                      <Link
                        to={`/vehicles/${document.vehicle_context.vehicle_id}`}
                        className="text-blue-600 hover:text-blue-800 hover:underline font-medium"
                      >
                        {document.vehicle_context.display ?? t('common:status.unknown')}
                      </Link>
                    ) : (
                      <span className="text-gray-900">{document.vehicle_context.display ?? t('common:status.unknown')}</span>
                    )}
                  </dd>
                  {document.vehicle_context.mileage && (
                    <dd className="mt-1 text-sm text-gray-500">
                      {t('documents.mileage', 'Mileage')}: {document.vehicle_context.mileage.toLocaleString()} km
                    </dd>
                  )}
                </div>
              </div>
            )}
          </dl>
        </div>

        {/* Document Info */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">
            {t('documents.documentInfo')}
          </h2>
          <dl className="space-y-3">
            <div className="flex items-start gap-3">
              <Calendar className="mt-0.5 h-5 w-5 text-gray-400" />
              <div>
                <dt className="text-sm font-medium text-gray-500">{t('documents.issueDate')}</dt>
                <dd className="text-gray-900">
                  {new Date(document.document_date).toLocaleDateString()}
                </dd>
              </div>
            </div>
            {document.due_date && (
              <div className="flex items-start gap-3">
                <Calendar className="mt-0.5 h-5 w-5 text-gray-400" />
                <div>
                  <dt className="text-sm font-medium text-gray-500">{t('documents.dueDate')}</dt>
                  <dd className="text-gray-900">
                    {new Date(document.due_date).toLocaleDateString()}
                  </dd>
                </div>
              </div>
            )}
          </dl>
        </div>

        {/* Totals */}
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">{t('documents.totals')}</h2>
          <dl className="space-y-3">
            <div className="flex justify-between">
              <dt className="text-sm text-gray-500">{t('documents.subtotal')}</dt>
              <dd className="text-sm font-medium text-gray-900">
                {formatAmount(document.subtotal ?? 0)}
              </dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-sm text-gray-500">{t('documents.tax')}</dt>
              <dd className="text-sm font-medium text-gray-900">
                {formatAmount(document.tax_amount ?? 0)}
              </dd>
            </div>
            <div className="border-t border-gray-200 pt-3">
              <div className="flex justify-between">
                <dt className="text-base font-semibold text-gray-900">{t('documents.total')}</dt>
                <dd className="text-base font-semibold text-gray-900">
                  {formatAmount(document.total ?? 0)}
                </dd>
              </div>
            </div>
            {/* Balance Due section for invoices and confirmed sales orders */}
            {((document.type === 'invoice' && document.status === 'posted') ||
              (document.type === 'sales_order' && document.status === 'confirmed')) && (
              <>
                <div className="border-t border-gray-200 pt-3">
                  <div className="flex justify-between items-center">
                    <dt className="text-base font-semibold text-gray-900">
                      {document.type === 'sales_order' ? t('documents.amountDue') : t('documents.balanceDue')}
                    </dt>
                    <dd className="text-base font-semibold">
                      {(() => {
                        const balanceDue = parseFloat(document.balance_due ?? document.total ?? '0')
                        const total = parseFloat(document.total ?? '0')
                        const isPaid = balanceDue === 0
                        const isOverdue = document.due_date && new Date(document.due_date) < new Date() && balanceDue > 0

                        return (
                          <span className={
                            isPaid ? 'text-green-600' :
                            isOverdue ? 'text-red-600' :
                            balanceDue < total ? 'text-yellow-600' :
                            'text-gray-900'
                          }>
                            {formatAmount(balanceDue)}
                          </span>
                        )
                      })()}
                    </dd>
                  </div>
                </div>
                {/* Payment Status Badge */}
                <div className="flex justify-between items-center">
                  <dt className="text-sm text-gray-500">{t('documents.paymentStatus')}</dt>
                  <dd>
                    {(() => {
                      const balanceDue = parseFloat(document.balance_due ?? document.total ?? '0')
                      const total = parseFloat(document.total ?? '0')

                      if (balanceDue === 0) {
                        return (
                          <span className="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-100 text-green-800">
                            {t('documents.statuses.paid')}
                          </span>
                        )
                      }

                      if (document.type === 'invoice' && document.due_date && new Date(document.due_date) < new Date()) {
                        const daysOverdue = Math.floor((new Date().getTime() - new Date(document.due_date).getTime()) / (1000 * 60 * 60 * 24))
                        return (
                          <span className="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-red-100 text-red-800">
                            {t('documents.statuses.overdue')} ({daysOverdue}d)
                          </span>
                        )
                      }

                      if (balanceDue < total) {
                        return (
                          <span className="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-yellow-100 text-yellow-800">
                            {document.type === 'sales_order' ? t('documents.statuses.prepaid') : t('documents.statuses.partial')}
                          </span>
                        )
                      }

                      return (
                        <span className="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium bg-orange-100 text-orange-800">
                          {t('documents.statuses.unpaid')}
                        </span>
                      )
                    })()}
                  </dd>
                </div>
              </>
            )}
          </dl>
        </div>
      </div>

      {/* Lines */}
      <div className="rounded-lg border border-gray-200 bg-white">
        <div className="border-b border-gray-200 px-6 py-4">
          <h2 className="text-lg font-semibold text-gray-900">
            <FileText className="me-2 inline h-5 w-5" />
            {t('documents.lineItems')}
          </h2>
        </div>
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.item')}
              </th>
              <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.description')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.quantity')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.unitPrice')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.tax')}
              </th>
              <th className="px-6 py-3 text-end text-xs font-medium uppercase tracking-wider text-gray-500">
                {t('lineItems.total')}
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200 bg-white">
            {document.lines.map((line) => (
              <tr key={line.id}>
                <td className="whitespace-nowrap px-6 py-4 text-sm font-medium">
                  {line.product_id ? (
                    <Link
                      to={`/inventory/products/${line.product_id}`}
                      className="text-blue-600 hover:text-blue-800 hover:underline"
                    >
                      {line.product_name}
                    </Link>
                  ) : (
                    <span className="text-gray-900">{line.product_name}</span>
                  )}
                </td>
                <td className="px-6 py-4 text-sm text-gray-500">
                  {line.description}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {line.quantity}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-900">
                  {formatAmount(line.unit_price)}
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm text-gray-500">
                  {line.tax_rate}%
                </td>
                <td className="whitespace-nowrap px-6 py-4 text-end text-sm font-medium text-gray-900">
                  {formatAmount(line.line_total)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Landed Cost Breakdown (for confirmed/posted purchase orders) */}
      {document.type === 'purchase_order' && (document.status === 'confirmed' || document.status === 'posted') && (
        <PurchaseOrderLandedCostBreakdown
          documentId={document.id}
          currency={currentCompany?.currency ?? 'USD'}
        />
      )}

      {/* Return Note Metadata (for return notes) */}
      {document.type === 'return_note' && (document as any).metadata && (
        <ReturnNoteMetadata
          metadata={(document as any).metadata}
          sourceDeliveryNoteNumber={(document as any).source_delivery_note_number}
          sourceInvoiceNumber={(document as any).source_invoice_number}
          linkedCreditNoteNumber={(document as any).linked_credit_note_number}
        />
      )}

      {/* Payment History Section */}
      {document.payments && document.payments.length > 0 && (
        <div className="rounded-lg border border-gray-200 bg-white">
          <div className="border-b border-gray-200 px-6 py-4">
            <h2 className="text-lg font-semibold text-gray-900">
              <CreditCard className="me-2 inline h-5 w-5" />
              {t('documents.paymentHistory')}
            </h2>
          </div>
          <div className="divide-y divide-gray-100">
            {document.payments.map((payment) => (
              <div key={payment.id} className="flex items-center justify-between px-6 py-4">
                <div className="flex items-center gap-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-full bg-green-100">
                    <CreditCard className="h-5 w-5 text-green-600" />
                  </div>
                  <div>
                    <p className="font-medium text-gray-900">
                      {payment.payment_method ?? t('documents.payment')}
                    </p>
                    <p className="text-sm text-gray-500">
                      {new Date(payment.payment_date).toLocaleDateString()}
                      {payment.payment_reference && (
                        <span className="ms-2 text-gray-400">
                          {t('documents.ref')}: {payment.payment_reference}
                        </span>
                      )}
                    </p>
                  </div>
                </div>
                <div className="text-end">
                  <p className="font-semibold text-green-600">
                    {formatAmount(payment.amount)}
                  </p>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Notes */}
      {document.notes && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">{t('documents.notes')}</h2>
          <p className="whitespace-pre-wrap text-gray-700">{document.notes}</p>
        </div>
      )}

      {/* Credit Notes Section - for posted invoices with credit notes */}
      {document.type === 'invoice' && document.status === 'posted' && creditNotes.length > 0 && (
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-gray-900">
            {t('documents.creditNotes', 'Credit Notes')}
          </h2>
          <CreditNoteList
            creditNotes={creditNotes}
            onSelect={(creditNote: CreditNote) => { setSelectedCreditNoteId(creditNote.id) }}
          />
        </div>
      )}

      {/* Related Documents Section */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-gray-900">
          <Link2 className="me-2 inline h-5 w-5" />
          {t('sales:relatedDocuments.title', 'Related Documents')}
        </h2>
        <RelatedDocumentsTab
          documentId={document.id}
          {...(currentCompany?.currency ? { currency: currentCompany.currency } : {})}
        />
      </div>

      {/* Attachments Section */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-gray-900">
          <Paperclip className="me-2 inline h-5 w-5" />
          {t('documents:attachments.title', 'Attachments')}
        </h2>
        <DocumentAttachments
          documentId={document.id}
          readOnly={document.status === 'cancelled'}
        />
      </div>

      {/* Confirmation Dialogs */}
      <ConfirmDialog
        isOpen={confirmAction === 'confirm'}
        onClose={() => { setConfirmAction(null) }}
        onConfirm={() => {
          confirmMutation.mutate()
          setConfirmAction(null)
        }}
        title={t('confirmation.confirmDocument.title', { type: getTypeLabel(document.type) })}
        message={t('confirmation.confirmDocument.message', { type: getTypeLabel(document.type).toLowerCase() })}
        confirmText={t('confirmation.confirmDocument.button')}
        variant="info"
        isLoading={confirmMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'cancel'}
        onClose={() => { setConfirmAction(null) }}
        onConfirm={() => {
          cancelMutation.mutate()
          setConfirmAction(null)
        }}
        title={t('confirmation.cancelDocument.title', { type: getTypeLabel(document.type) })}
        message={t('confirmation.cancelDocument.message', { type: getTypeLabel(document.type).toLowerCase() })}
        confirmText={t('confirmation.cancelDocument.button')}
        variant="danger"
        isLoading={cancelMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'post'}
        onClose={() => { setConfirmAction(null) }}
        onConfirm={() => {
          postMutation.mutate()
          setConfirmAction(null)
        }}
        title={t('confirmation.postDocument.title', { type: getTypeLabel(document.type) })}
        message={t('confirmation.postDocument.message', { type: getTypeLabel(document.type).toLowerCase() })}
        confirmText={t('confirmation.postDocument.button')}
        variant="warning"
        isLoading={postMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'convert'}
        onClose={() => { setConfirmAction(null) }}
        onConfirm={() => {
          if (conversionTarget != null) {
            convertMutation.mutate(conversionTarget.targetType)
          }
          setConfirmAction(null)
        }}
        title={t('confirmation.convertDocument.title', { target: getTypeLabel(conversionTarget?.targetType ?? 'order') })}
        message={t('confirmation.convertDocument.message', { source: getTypeLabel(document.type).toLowerCase(), target: getTypeLabel(conversionTarget?.targetType ?? 'order').toLowerCase() })}
        confirmText={t('confirmation.convertDocument.button')}
        variant="warning"
        isLoading={convertMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'convertToDelivery'}
        onClose={() => { setConfirmAction(null) }}
        onConfirm={() => {
          convertToDeliveryMutation.mutate()
          setConfirmAction(null)
        }}
        title={t('confirmation.convertDocument.title', { target: t('documents.types.delivery_note') })}
        message={t('confirmation.convertDocument.message', { source: t('documents.types.sales_order').toLowerCase(), target: t('documents.types.delivery_note').toLowerCase() })}
        confirmText={t('confirmation.convertDocument.button')}
        variant="warning"
        isLoading={convertToDeliveryMutation.isPending}
      />

      <ConfirmDialog
        isOpen={confirmAction === 'receiveGoods'}
        onClose={() => { setConfirmAction(null) }}
        onConfirm={() => {
          receiveGoodsMutation.mutate()
          setConfirmAction(null)
        }}
        title={t('purchaseOrders.receiveGoodsConfirm.title')}
        message={t('purchaseOrders.receiveGoodsConfirm.message')}
        confirmText={t('purchaseOrders.receiveGoodsConfirm.button')}
        variant="info"
        isLoading={receiveGoodsMutation.isPending}
      />

      {/* Record Payment Modal */}
      {canRecordPayment && (
        <RecordPaymentModal
          isOpen={showPaymentModal}
          onClose={() => { setShowPaymentModal(false) }}
          onSuccess={() => {
            void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
            toast.success(t('treasury:payments.recordedSuccess', 'Payment recorded successfully'))
          }}
          prefill={{
            partner_id: document.partner_id,
            partner_name: document.partner_name ?? '',
            amount: parseFloat(document.balance_due ?? document.total ?? '0'),
            reference: document.document_number,
            document_id: document.id,
            document_type: document.type === 'sales_order' ? 'sales_order' : 'invoice',
          }}
        />
      )}

      {/* Create Credit Note Modal */}
      {document.type === 'invoice' && (
        <Modal
          isOpen={showCreditNoteForm}
          onClose={() => { setShowCreditNoteForm(false) }}
          title={t('documents.createCreditNote')}
          size="lg"
        >
          <CreateCreditNoteForm
            invoice={{
              id: document.id,
              document_number: document.document_number,
              document_date: document.document_date,
              partner: {
                id: document.partner_id,
                name: document.partner_name ?? '',
              },
              total: document.total ?? '0',
              balance_due: document.balance_due ?? document.total ?? '0',
              currency: (document as { currency?: string }).currency ?? currentCompany?.currency ?? 'USD',
              status: document.status as DocumentStatus,
            }}
            onSuccess={() => {
              setShowCreditNoteForm(false)
              void queryClient.invalidateQueries({ queryKey: ['credit-notes'] })
              void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
              toast.success(t('documents.creditNoteCreated', 'Credit note created successfully'))
            }}
            onCancel={() => { setShowCreditNoteForm(false) }}
          />
        </Modal>
      )}

      {/* Create Return Note Modal - for invoices and delivery notes */}
      {(document.type === 'invoice' || document.type === 'delivery_note') && (
        <Modal
          isOpen={showReturnNoteForm}
          onClose={() => { setShowReturnNoteForm(false) }}
          title={t('returnNotes.createFromInvoice', 'Create Return Note')}
          size="lg"
        >
          <CreateReturnNoteForm
            sourceDocument={{
              id: document.id,
              document_number: document.document_number,
              document_date: document.document_date,
              partner_name: document.partner_name ?? '',
              total: document.total ?? '0',
            }}
            sourceType={document.type === 'invoice' ? 'invoice' : 'delivery_note'}
            onSuccess={() => {
              setShowReturnNoteForm(false)
              void queryClient.invalidateQueries({ queryKey: ['return-notes'] })
              void queryClient.invalidateQueries({ queryKey: ['document', contextType, id] })
              toast.success(t('returnNotes.messages.created', 'Return note created successfully'))
            }}
            onCancel={() => { setShowReturnNoteForm(false) }}
          />
        </Modal>
      )}

      {/* Credit Note Detail Modal */}
      {selectedCreditNote && (
        <Modal
          isOpen={selectedCreditNote !== null}
          onClose={() => { setSelectedCreditNoteId(null) }}
          title={t('documents.creditNoteDetails', 'Credit Note Details')}
          size="lg"
        >
          <CreditNoteDetail
            creditNote={selectedCreditNote}
            onClose={() => { setSelectedCreditNoteId(null) }}
          />
        </Modal>
      )}

      {/* Send Email Modal */}
      <Modal
        isOpen={showEmailModal}
        onClose={() => { setShowEmailModal(false) }}
        title={t('common:email.title')}
        size="md"
      >
        <form
          onSubmit={(e) => {
            e.preventDefault()
            const ccEmailsArray = emailForm.ccEmails
              .split(',')
              .map((email) => email.trim())
              .filter((email) => email.length > 0)

            sendEmailMutation.mutate(
              {
                documentId: id,
                recipientEmail: emailForm.recipientEmail || undefined,
                subject: emailForm.subject || undefined,
                message: emailForm.message || undefined,
                ccEmails: ccEmailsArray.length > 0 ? ccEmailsArray : undefined,
              },
              {
                onSuccess: () => {
                  toast.success(t('common:email.success'))
                  setShowEmailModal(false)
                  setEmailForm({
                    recipientEmail: '',
                    subject: '',
                    message: '',
                    ccEmails: '',
                  })
                },
                onError: () => {
                  toast.error(t('common:email.error'))
                },
              }
            )
          }}
          className="space-y-4"
        >
          <div>
            <label htmlFor="recipientEmail" className="block text-sm font-medium text-gray-700">
              {t('common:email.recipientEmail')}
            </label>
            <input
              type="email"
              id="recipientEmail"
              value={emailForm.recipientEmail}
              onChange={(e) => { setEmailForm({ ...emailForm, recipientEmail: e.target.value }) }}
              placeholder={t('common:email.recipientEmailPlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
            <p className="mt-1 text-xs text-gray-500">
              {t('common:email.recipientEmailHint')}
            </p>
          </div>

          <div>
            <label htmlFor="subject" className="block text-sm font-medium text-gray-700">
              {t('common:email.subject')}
            </label>
            <input
              type="text"
              id="subject"
              value={emailForm.subject}
              onChange={(e) => { setEmailForm({ ...emailForm, subject: e.target.value }) }}
              placeholder={t('common:email.subjectPlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label htmlFor="message" className="block text-sm font-medium text-gray-700">
              {t('common:email.message')}
            </label>
            <textarea
              id="message"
              rows={4}
              value={emailForm.message}
              onChange={(e) => { setEmailForm({ ...emailForm, message: e.target.value }) }}
              placeholder={t('common:email.messagePlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div>
            <label htmlFor="ccEmails" className="block text-sm font-medium text-gray-700">
              {t('common:email.ccEmails')}
            </label>
            <input
              type="text"
              id="ccEmails"
              value={emailForm.ccEmails}
              onChange={(e) => { setEmailForm({ ...emailForm, ccEmails: e.target.value }) }}
              placeholder={t('common:email.ccEmailsPlaceholder')}
              className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
            />
          </div>

          <div className="flex justify-end gap-3 pt-4">
            <button
              type="button"
              onClick={() => { setShowEmailModal(false) }}
              className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
            >
              {t('common:cancel')}
            </button>
            <button
              type="submit"
              disabled={sendEmailMutation.isPending}
              className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >
              <Send className="h-4 w-4" />
              {sendEmailMutation.isPending ? t('common:email.sending') : t('common:email.send')}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
