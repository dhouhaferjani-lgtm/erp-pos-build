import { useEffect, useState, useMemo } from 'react'
import { Link, useNavigate, useParams, useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, Controller, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, apiPatch } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { DocumentLineEditor, type DocumentLine } from '../../components/documents/DocumentLineEditor'
import { PurchaseOrderAdditionalCosts } from './components/PurchaseOrderAdditionalCosts'
import { StickyFormFooter } from '../../components/molecules/StickyFormFooter/StickyFormFooter'
import { AddPartnerModal } from '../../components/organisms'
import { PartnerSearchSelect } from '../../components/ui/PartnerSearchSelect'
import { useCompany } from '../../hooks/useCompany'
import { useDraftAutoSave } from '../../hooks/useDraftAutoSave'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import type { DocumentType } from './DocumentListPage'
import type { Document } from '../../types/document'

// Determine whether to filter by customer or supplier based on document type
function getPartnerTypeForDocument(docType: DocumentType | undefined): 'customer' | 'supplier' | undefined {
  if (!docType) return undefined
  // Sales documents need customers
  if (['quote', 'sales_order', 'invoice', 'credit_note', 'delivery_note', 'return_note'].includes(docType)) {
    return 'customer'
  }
  // Purchase documents need suppliers
  if (docType === 'purchase_order') {
    return 'supplier'
  }
  return undefined
}

interface DocumentFormData {
  type: 'quote' | 'order' | 'invoice' | 'credit_note' | 'delivery_note' | 'sales_order' | 'purchase_order' | 'return_note' | ''
  partner_id: string
  issue_date: string
  due_date: string
  notes: string
  external_document_number: string
  external_document_date: string
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

const documentTypeToTitle: Record<DocumentType, string> = {
  quote: 'Quote',
  sales_order: 'Sales Order',
  invoice: 'Invoice',
  purchase_order: 'Purchase Order',
  delivery_note: 'Delivery Note',
  credit_note: 'Credit Note',
  return_note: 'Return Note',
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
  if (pathname.includes('/sales/credit-notes')) return 'credit_note'
  return undefined
}

interface DocumentFormProps {
  documentType?: DocumentType
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

export function DocumentForm({ documentType }: DocumentFormProps) {
  const { t } = useTranslation()
  const { id = '' } = useParams<{ id: string }>()
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { currentCompany } = useCompany()
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  const isEditing = id.length > 0

  // Track if initial lines have been loaded
  const [hasInitializedLines, setHasInitializedLines] = useState(false)
  // Track if URL partner has been applied
  const [hasAppliedUrlPartner, setHasAppliedUrlPartner] = useState(false)
  // Lines state (managed separately from form)
  const [lines, setLines] = useState<DocumentLine[]>([])
  // Partner modal state
  const [showPartnerModal, setShowPartnerModal] = useState(false)

  // Parse URL query parameters for pre-population
  const searchParams = new URLSearchParams(location.search)
  const urlCustomerId = searchParams.get('customer')
  const urlSupplierId = searchParams.get('supplier')
  const urlPartnerId = urlCustomerId ?? urlSupplierId

  // Determine document type from props or URL
  const effectiveType = documentType ?? getDocumentTypeFromPath(location.pathname)
  const basePath = effectiveType ? documentTypeToPath[effectiveType] : '/documents'
  const apiEndpoint = effectiveType ? documentTypeToApiEndpoint[effectiveType] : '/documents'
  const entityName = effectiveType ? documentTypeToTitle[effectiveType] : 'Document'

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    control,
    formState: { errors, isSubmitting },
  } = useForm<DocumentFormData>({
    defaultValues: {
      type: effectiveType ?? '',
      partner_id: '',
      issue_date: new Date().toISOString().split('T')[0],
      due_date: '',
      notes: '',
      external_document_number: '',
      external_document_date: '',
    },
  })

  // Determine partner type to filter based on document type
  const partnerTypeFilter = getPartnerTypeForDocument(effectiveType)

  // Watch form values for auto-save
  const watchedPartnerId = useWatch({ control, name: 'partner_id' })
  const watchedNotes = useWatch({ control, name: 'notes' })
  const watchedDocumentDate = useWatch({ control, name: 'issue_date' })
  const watchedDueDate = useWatch({ control, name: 'due_date' })

  // Construct draft data for auto-save
  const draftData = useMemo(() => {
    if (!effectiveType) return null

    return {
      type: effectiveType,
      partner_id: watchedPartnerId || null,
      notes: watchedNotes || null,
      document_date: watchedDocumentDate,
      due_date: watchedDueDate || null,
      lines: lines.map(line => ({
        id: line.id,
        product_id: line.product_id,
        quantity: line.quantity,
        unit_price: line.unit_price,
        tax_rate: line.tax_rate || 0,
      })),
    }
  }, [effectiveType, watchedPartnerId, watchedNotes, watchedDocumentDate, watchedDueDate, lines])

  // Auto-save hook (works for both new and existing documents)
  const { draftId: _draftId, isSaving, lastSavedAt } = useDraftAutoSave(
    draftData,
    {
      enabled: true,
      existingDraftId: id || undefined,
      onSuccess: (_savedDraftId) => {
        // draft ID is tracked internally by the hook
      },
      onError: () => {
        // Auto-save failed silently
      },
    }
  )

  // Fetch document data when editing
  const { data: document, isLoading } = useQuery({
    queryKey: tenantScopedKey(['document', effectiveType, id]),
    queryFn: async () => {
      const response = await api.get<{ data: Document }>(`${apiEndpoint}/${id}`)
      return response.data.data
    },
    enabled: isEditing && apiEndpoint !== '/documents' && tenantId !== null && companyId !== null,
  })

  // Populate form when document data loads
  useEffect(() => {
    if (document) {
      reset({
        type: document.type as DocumentFormData['type'],
        partner_id: document.partner_id ?? '',
        issue_date: document.issue_date ?? '',
        due_date: document.due_date ?? '',
        notes: document.notes ?? '',
        external_document_number: document.external_document_number ?? '',
        external_document_date: document.external_document_date ?? '',
      })
    }
  }, [document, reset])

  // Pre-populate partner from URL query parameter (when creating new document)
  useEffect(() => {
    // Only apply URL partner for new documents, not when editing
    if (isEditing || hasAppliedUrlPartner || !urlPartnerId) {
      return
    }

    // Set the partner ID from URL
    setValue('partner_id', urlPartnerId)
    setHasAppliedUrlPartner(true)
  }, [isEditing, hasAppliedUrlPartner, urlPartnerId, setValue])

  // Initialize lines from document (only once when document first loads)
  if (document?.lines && !hasInitializedLines) {
    setLines(document.lines.map((l) => ({
      id: l.id,
      product_id: l.product_id ?? '',
      product_code: '',
      product_name: l.product_name,
      description: l.description,
      quantity: parseFloat(l.quantity),
      unit_price: parseFloat(l.unit_price),
      tax_rate: parseFloat(l.tax_rate ?? '0'),
      line_total: parseFloat(l.line_total),
    })))
    setHasInitializedLines(true)
  }

  const createMutation = useMutation({
    mutationFn: (data: DocumentFormData) => apiPost<Document>(apiEndpoint, data),
    onSuccess: async (response) => {
      toast.success(t('status.success'))
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([effectiveType]) }),
      ])
      const documentId = response?.id
      if (documentId) {
        // For purchase orders, redirect to edit mode so user can add additional costs
        if (effectiveType === 'purchase_order') {
          void navigate(`${basePath}/${documentId}/edit`)
        } else {
          void navigate(`${basePath}/${documentId}`)
        }
      }
    },
    onError: (error: Error & { response?: { data?: { message?: string; error?: { message?: string } } } }) => {
      const message = error.response?.data?.error?.message
        ?? error.response?.data?.message
        ?? error.message
      toast.error(message)
    },
  })

  const updateMutation = useMutation({
    mutationFn: (data: DocumentFormData) =>
      apiPatch<Document>(`${apiEndpoint}/${id}`, data),
    onSuccess: async () => {
      toast.success(t('status.success'))
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([effectiveType]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document', effectiveType, id]) }),
      ])
      void navigate(`${basePath}/${id}`)
    },
    onError: (error: Error & { response?: { data?: { message?: string; error?: { message?: string } } } }) => {
      const message = error.response?.data?.error?.message
        ?? error.response?.data?.message
        ?? error.message
      toast.error(message)
    },
  })

  const onSubmit = (data: DocumentFormData) => {
    // Ensure the type is set from context if not in form
    const submitData = {
      ...data,
      type: data.type || effectiveType || '',
      lines: lines.map((line) => ({
        product_id: line.product_id,
        description: line.description,
        quantity: line.quantity,
        unit_price: line.unit_price,
        tax_rate: line.tax_rate,
      })),
      // Include external document fields for purchase orders
      external_document_number: data.external_document_number || null,
      external_document_date: data.external_document_date || null,
    }
    if (isEditing) {
      updateMutation.mutate(submitData as DocumentFormData)
    } else {
      createMutation.mutate(submitData as DocumentFormData)
    }
  }

  if (isEditing && isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-gray-500">{t('status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Link
          to={basePath}
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEditing ? `${t('actions.edit')} ${entityName}` : `${t('actions.add')} ${entityName}`}
        </h1>
      </div>

      {/* Form */}
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="flex flex-1 flex-col gap-6">
        <div className="rounded-lg border border-gray-200 bg-white p-6">
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Type - hidden if type is set from context */}
            {!effectiveType && (
              <div>
                <label
                  htmlFor="type"
                  className="block text-sm font-medium text-gray-700"
                >
                  {t('sales:documents.type')} *
                </label>
                <select
                  id="type"
                  {...register('type', { required: t('sales:documents.typeRequired') })}
                  className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  disabled={isEditing}
                >
                  <option value="">{t('sales:documents.selectType')}</option>
                  <option value="quote">{t('sales:documents.types.quote')}</option>
                  <option value="sales_order">{t('sales:documents.types.sales_order')}</option>
                  <option value="invoice">{t('sales:documents.types.invoice')}</option>
                  <option value="purchase_order">{t('sales:documents.types.purchase_order')}</option>
                  <option value="credit_note">{t('sales:documents.types.credit_note')}</option>
                  <option value="delivery_note">{t('sales:documents.types.delivery_note')}</option>
                </select>
                {errors.type && (
                  <p className="mt-1 text-sm text-red-600">{errors.type.message}</p>
                )}
              </div>
            )}

            {/* Partner */}
            <div>
              <label
                htmlFor="partner_id"
                className="block text-sm font-medium text-gray-700"
              >
                {partnerTypeFilter === 'customer' ? t('partners.customer', 'Customer') : partnerTypeFilter === 'supplier' ? t('partners.supplier', 'Supplier') : t('partners.partner', 'Partner')} *
              </label>
              <div className="mt-1">
                <Controller
                  name="partner_id"
                  control={control}
                  rules={{
                    required: t('validation.required', 'This field is required'),
                  }}
                  render={({ field }) => (
                    <PartnerSearchSelect
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      partnerType={partnerTypeFilter}
                      error={errors.partner_id?.message}
                      onAddNew={() => { setShowPartnerModal(true) }}
                    />
                  )}
                />
              </div>
              {errors.partner_id && (
                <p className="mt-1 text-sm text-red-600">
                  {errors.partner_id.message}
                </p>
              )}
            </div>

            {/* Issue Date */}
            <div>
              <label
                htmlFor="issue_date"
                className="block text-sm font-medium text-gray-700"
              >
                {t('sales:documents.issueDate')} *
              </label>
              <input
                type="date"
                id="issue_date"
                {...register('issue_date', { required: t('validation.required') })}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
              {errors.issue_date && (
                <p className="mt-1 text-sm text-red-600">
                  {errors.issue_date.message}
                </p>
              )}
            </div>

            {/* Due Date */}
            <div>
              <label
                htmlFor="due_date"
                className="block text-sm font-medium text-gray-700"
              >
                {t('sales:documents.dueDate')}
              </label>
              <input
                type="date"
                id="due_date"
                {...register('due_date')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* Supplier Invoice Reference (Purchase Orders only) */}
            {effectiveType === 'purchase_order' && (
              <>
                <div>
                  <label
                    htmlFor="external_document_number"
                    className="block text-sm font-medium text-gray-700"
                  >
                    {t('purchases.supplierInvoiceNumber', 'Supplier Invoice #')}
                  </label>
                  <input
                    type="text"
                    id="external_document_number"
                    {...register('external_document_number')}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                    placeholder={t('purchases.supplierInvoiceNumberPlaceholder', 'e.g., INV-2025-001')}
                  />
                </div>

                <div>
                  <label
                    htmlFor="external_document_date"
                    className="block text-sm font-medium text-gray-700"
                  >
                    {t('purchases.supplierInvoiceDate', 'Supplier Invoice Date')}
                  </label>
                  <input
                    type="date"
                    id="external_document_date"
                    {...register('external_document_date')}
                    className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                  />
                </div>
              </>
            )}

            {/* Notes */}
            <div className="sm:col-span-2">
              <label
                htmlFor="notes"
                className="block text-sm font-medium text-gray-700"
              >
                {t('sales:documents.notes')}
              </label>
              <textarea
                id="notes"
                rows={4}
                {...register('notes')}
                className="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder={t('sales:documents.notesPlaceholder', 'Additional notes...')}
              />
            </div>
          </div>
        </div>

        {/* Document Lines */}
        <DocumentLineEditor lines={lines} onChange={setLines} {...(effectiveType ? { documentType: effectiveType } : {})} />

        {/* Additional Costs (Purchase Orders only - after document is created) */}
        {effectiveType === 'purchase_order' && isEditing && id && (
          <PurchaseOrderAdditionalCosts
            documentId={id}
            disabled={document?.status !== 'draft'}
            currency={currentCompany?.currency ?? 'TND'}
          />
        )}

        {/* Form Actions */}
        <StickyFormFooter>
          {/* Auto-save indicator (only for new documents) */}
          {!isEditing && (
            <div className="mr-auto flex items-center gap-2 text-sm text-gray-500">
              {isSaving ? (
                <>
                  <svg className="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <span>{t('saving')}</span>
                </>
              ) : lastSavedAt ? (
                <>
                  <svg className="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                  </svg>
                  <span>
                    {t('sales:documents.draftSavedAt', { time: lastSavedAt.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) })}
                  </span>
                </>
              ) : null}
            </div>
          )}

          <Link
            to={basePath}
            className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            {t('actions.cancel')}
          </Link>
          <button
            type="submit"
            disabled={isSubmitting || createMutation.isPending || updateMutation.isPending}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {(isSubmitting || createMutation.isPending || updateMutation.isPending) ? t('status.saving') : t('actions.save')}
          </button>
        </StickyFormFooter>
      </form>

      {/* Add Partner Modal */}
      <AddPartnerModal
        isOpen={showPartnerModal}
        onClose={() => { setShowPartnerModal(false) }}
        partnerType={partnerTypeFilter}
        onSuccess={(partner) => {
          // Set the form value immediately
          setValue('partner_id', partner.id)
          // Invalidate partners query to refresh the dropdown with new partner
          void queryClient.invalidateQueries({
            predicate: scopedNamespacePredicate('partners', tenantId, companyId),
          })
        }}
      />
    </div>
  )
}
