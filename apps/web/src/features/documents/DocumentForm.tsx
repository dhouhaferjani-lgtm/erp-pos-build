import { useCallback, useEffect, useState, useMemo, useRef } from 'react'
import { Link, useNavigate, useParams, useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm, Controller, useWatch } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Check, Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { api, apiPost, apiPatch } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { cn } from '../../lib/utils'
import { tokens, textColors } from '../../lib/designTokens'
import { DocumentLineEditor, type DocumentLine } from '../../components/documents/DocumentLineEditor'
import { PurchaseOrderAdditionalCosts } from './components/PurchaseOrderAdditionalCosts'
import { StickyFormFooter } from '../../components/molecules/StickyFormFooter/StickyFormFooter'
import { PageHeader } from '../../components/molecules/PageHeader'
import { SaveSplitButton } from '@/components/molecules/SaveSplitButton'
import { Button, FormField, Input, Select, Textarea } from '../../components/atoms'
import { AddPartnerModal } from '../../components/organisms'
import { PartnerSearchSelect } from '../../components/ui/PartnerSearchSelect'
import { useCompany } from '../../hooks/useCompany'
import { useDraftAutoSave } from '../../hooks/useDraftAutoSave'
import { useAfterSaveNavigation } from '@/hooks/useAfterSaveNavigation'
import { useUnsavedChangesGuard, confirmDiscard } from '@/hooks/useUnsavedChangesGuard'
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

/**
 * Pure helper: determines whether lines have changed relative to the last saved snapshot.
 * Exported for unit testing.
 *
 * @param lastSavedLines  JSON snapshot at last save; null = never saved
 * @param lines           current lines array
 */
export function computeLinesDirty(lastSavedLines: string | null, lines: unknown[]): boolean {
  return lastSavedLines === null ? lines.length > 0 : JSON.stringify(lines) !== lastSavedLines
}

interface LinePayload {
  product_id: string
  quantity: string | number
  unit_price: string | number
  line_total: string | number
  price_entry_mode: 'unit' | 'total'
  discount_percent: string | null
  discount_amount: string | null
  free_quantity?: string | number
}

/**
 * True when a free_quantity value is empty or represents zero (e.g. '', '0',
 * '0.0000'). String-based check on purpose — never parseFloat on quantities.
 */
function isZeroFreeQuantity(value: DocumentLine['free_quantity']): boolean {
  if (value === null || value === undefined) return true
  const trimmed = String(value).trim()
  return trimmed === '' || /^0+(\.0+)?$/.test(trimmed)
}

/**
 * Pure helper: maps a DocumentLine to the API line payload shared by BOTH
 * the autosave draft payload and the submit payload (single source of truth
 * so the two sites cannot drift). Exported for unit testing.
 *
 * `free_quantity` is OMITTED when empty/zero: the backend
 * (Create/UpdateDocumentRequest) prohibits `lines.*.free_quantity` whenever
 * the purchase-bonus module gate is disabled for the company, so sending the
 * default '0' fails every document creation with a 422
 * ("Le champ lines.0.free_quantity est interdit."). A real non-zero bonus
 * quantity (purchase flow with the module enabled) is sent exactly as entered.
 */
export function buildLinePayload(line: DocumentLine): LinePayload {
  const payload: LinePayload = {
    product_id: line.product_id,
    quantity: line.quantity,
    unit_price: line.unit_price,
    line_total: line.line_total,
    price_entry_mode: line.price_entry_mode ?? 'unit',
    discount_percent: line.discount_percent ?? null,
    discount_amount: line.discount_amount ?? null,
  }
  if (!isZeroFreeQuantity(line.free_quantity)) {
    payload.free_quantity = line.free_quantity as string | number
  }
  return payload
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
  // Snapshot of lines as of the last successful save (autosave or manual).
  // null = never saved; used to detect any change including clearing all lines.
  const lastSavedLinesRef = useRef<string | null>(null)

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
    getValues,
    control,
    formState: { errors, isSubmitting, isDirty },
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
        ...buildLinePayload(line),
        tax_rate: line.tax_rate || 0,
      })),
    }
  }, [effectiveType, watchedPartnerId, watchedNotes, watchedDocumentDate, watchedDueDate, lines])

  // Stable auto-save callbacks. These MUST be referentially stable: useDraftAutoSave
  // includes onSuccess/onError in performSave's deps, and the debounce effect depends on
  // performSave. Inline callbacks made performSave change every render, so a successful
  // autosave (which re-renders via reset()/lastSavedAt) re-armed the debounce — looping
  // autosave every ~3s and pinning autosavePending=true, which would raise a spurious
  // beforeunload prompt on an idle non-empty document.
  const handleAutoSaveSuccess = useCallback(() => {
    // Bug 2: reset RHF dirty baseline so isDirty becomes false after autosave.
    reset(getValues(), { keepDirty: false, keepDefaultValues: false })
    // Bug 3: snapshot current lines so clearing them later is detected.
    lastSavedLinesRef.current = JSON.stringify(lines)
  }, [reset, getValues, lines])
  const handleAutoSaveError = useCallback(() => {
    // Auto-save failed silently; autosaveFailed surfaces it to the guard.
  }, [])

  // Auto-save hook (works for both new and existing documents)
  const { draftId, isSaving, lastSavedAt, autosavePending, autosaveFailed } = useDraftAutoSave(
    draftData,
    {
      enabled: true,
      existingDraftId: id || undefined,
      onSuccess: handleAutoSaveSuccess,
      onError: handleAutoSaveError,
    }
  )

  // Post-save navigation (used by Save & Close)
  const nav = useAfterSaveNavigation({
    recordPath: (rid) => `${basePath}/${rid}`,
    listPath: basePath,
  })

  // Autosave-aware unsaved-changes guard
  // Bug 3: use snapshot comparison instead of `lines.length > 0 && !lastSavedAt`
  // so that clearing all lines after an autosave still triggers the guard.
  const linesDirty = computeLinesDirty(lastSavedLinesRef.current, lines)
  const docDirty = {
    // Bug 2: `isDirty` is now reset to false after each autosave, so we no
    // longer get false positives from RHF's stale dirty state.
    isDirty: isDirty || linesDirty,
    autosavePending,
    autosaveFailed,
  }
  // Bug 1: single source of truth for all warn conditions (used by Cancel,
  // breadcrumb, and the beforeunload guard via useUnsavedChangesGuard).
  const shouldWarn = docDirty.isDirty || docDirty.autosavePending || docDirty.autosaveFailed
  useUnsavedChangesGuard(docDirty)

  // Track Save & Close intent across async mutation callbacks
  const closeIntentRef = useRef(false)

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
    const initialLines = document.lines.map((l) => ({
      id: l.id,
      product_id: l.product_id ?? '',
      product_code: '',
      product_name: l.product_name,
      description: l.description,
      quantity: l.quantity,
      free_quantity: l.free_quantity ?? '0',
      free_quantity_received: l.free_quantity_received ?? '0',
      free_quantity_invoiced: l.free_quantity_invoiced ?? '0',
      unit_price: l.unit_price,
      discount_percent: l.discount_percent ?? null,
      discount_amount: l.discount_amount ?? null,
      tax_rate: l.tax_rate ?? '0',
      line_total: l.line_total,
      price_entry_mode: l.price_entry_mode ?? 'unit',
      landed_unit_cost: l.landed_unit_cost ?? null,
      is_bonus_line: l.is_bonus_line ?? false,
      quantity_decimals: l.quantity_decimals ?? null,
    }))
    setLines(initialLines)
    // Bug 3: treat the server-loaded lines as the saved baseline so that
    // any subsequent edit (including clearing all lines) is detected.
    lastSavedLinesRef.current = JSON.stringify(initialLines)
    setHasInitializedLines(true)
  }

  const createMutation = useMutation({
    mutationFn: (data: DocumentFormData) => apiPost<Document>(apiEndpoint, data),
    onSuccess: async (response) => {
      const shouldClose = closeIntentRef.current
      closeIntentRef.current = false
      // Bug 3: update saved-lines baseline so guard is cleared after manual save.
      lastSavedLinesRef.current = JSON.stringify(lines)
      toast.success(t('status.success'))
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([effectiveType]) }),
      ])
      const documentId = response?.id
      if (documentId) {
        if (shouldClose) { nav.goToList(); return }
        // For purchase orders, redirect to edit mode so user can add additional costs
        if (effectiveType === 'purchase_order') {
          void navigate(`${basePath}/${documentId}/edit`)
        } else {
          void navigate(`${basePath}/${documentId}`)
        }
      }
    },
    onError: (error: Error & { response?: { data?: { message?: string; error?: { message?: string } } } }) => {
      closeIntentRef.current = false
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
      const shouldClose = closeIntentRef.current
      closeIntentRef.current = false
      // Bug 3: update saved-lines baseline so guard is cleared after manual save.
      lastSavedLinesRef.current = JSON.stringify(lines)
      toast.success(t('status.success'))
      await Promise.all([
        queryClient.invalidateQueries({
          predicate: scopedNamespacePredicate('documents', tenantId, companyId),
        }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey([effectiveType]) }),
        queryClient.invalidateQueries({ queryKey: tenantScopedKey(['document', effectiveType, id]) }),
      ])
      if (shouldClose) { nav.goToList(); return }
      void navigate(`${basePath}/${id}`)
    },
    onError: (error: Error & { response?: { data?: { message?: string; error?: { message?: string } } } }) => {
      closeIntentRef.current = false
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
        ...buildLinePayload(line),
        description: line.description,
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

  const partnerLabel =
    partnerTypeFilter === 'customer'
      ? t('partners.customer', 'Customer')
      : partnerTypeFilter === 'supplier'
        ? t('partners.supplier', 'Supplier')
        : t('partners.partner', 'Partner')

  const isSubmitInProgress =
    isSubmitting || createMutation.isPending || updateMutation.isPending
  const docId = id || draftId || ''
  const additionalCostsDisabled = isEditing ? document?.status !== 'draft' : false

  if (isEditing && isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className={textColors.tertiary}>{t('status.loading')}</div>
      </div>
    )
  }

  return (
    <div className="flex min-h-full flex-col gap-6">
      {/* Header */}
      <PageHeader
        title={
          isEditing
            ? `${t('actions.edit')} ${entityName}`
            : `${t('actions.add')} ${entityName}`
        }
        breadcrumb={
          <Link
            to={basePath}
            className={cn(
              'inline-flex items-center gap-2 text-sm',
              textColors.tertiary,
              textColors.hoverPrimary,
            )}
            onClick={(e) => {
              // Bug 1: guard the breadcrumb back-link the same way as Cancel.
              if (shouldWarn && !confirmDiscard(t('confirmation.unsavedChangesBody'))) {
                e.preventDefault()
              }
            }}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
        }
        className="mb-0"
      />

      {/* Form */}
      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }} className="flex flex-1 flex-col gap-6">
        <div className={tokens.card.base}>
          <h2 className={cn(tokens.heading.section, 'mb-4')}>
            {t('sales:documents.details', 'Details')}
          </h2>
          <div className="grid gap-6 sm:grid-cols-2">
            {/* Type - hidden if type is set from context */}
            {!effectiveType && (
              <FormField
                label={t('sales:documents.type')}
                htmlFor="type"
                required
                error={errors.type?.message}
              >
                <Select
                  id="type"
                  {...register('type', { required: t('sales:documents.typeRequired') })}
                  error={Boolean(errors.type)}
                  disabled={isEditing}
                >
                  <option value="">{t('sales:documents.selectType')}</option>
                  <option value="quote">{t('sales:documents.types.quote')}</option>
                  <option value="sales_order">{t('sales:documents.types.sales_order')}</option>
                  <option value="invoice">{t('sales:documents.types.invoice')}</option>
                  <option value="purchase_order">{t('sales:documents.types.purchase_order')}</option>
                  <option value="credit_note">{t('sales:documents.types.credit_note')}</option>
                  <option value="delivery_note">{t('sales:documents.types.delivery_note')}</option>
                </Select>
              </FormField>
            )}

            {/* Partner */}
            <FormField label={partnerLabel} htmlFor="partner_id" required>
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
            </FormField>

            {/* Issue Date */}
            <FormField
              label={t('sales:documents.issueDate')}
              htmlFor="issue_date"
              required
              error={errors.issue_date?.message}
            >
              <Input
                type="date"
                id="issue_date"
                {...register('issue_date', { required: t('validation.required') })}
                error={Boolean(errors.issue_date)}
              />
            </FormField>

            {/* Due Date */}
            <FormField label={t('sales:documents.dueDate')} htmlFor="due_date">
              <Input type="date" id="due_date" {...register('due_date')} />
            </FormField>

            {/* Supplier Invoice Reference (Purchase Orders only) */}
            {effectiveType === 'purchase_order' && (
              <>
                <FormField
                  label={t('purchases.supplierInvoiceNumber', 'Supplier Invoice #')}
                  htmlFor="external_document_number"
                >
                  <Input
                    type="text"
                    id="external_document_number"
                    {...register('external_document_number')}
                    placeholder={t('purchases.supplierInvoiceNumberPlaceholder', 'e.g., INV-2025-001')}
                  />
                </FormField>

                <FormField
                  label={t('purchases.supplierInvoiceDate', 'Supplier Invoice Date')}
                  htmlFor="external_document_date"
                >
                  <Input
                    type="date"
                    id="external_document_date"
                    {...register('external_document_date')}
                  />
                </FormField>
              </>
            )}

            {/* Notes */}
            <FormField
              className="sm:col-span-2"
              label={t('sales:documents.notes')}
              htmlFor="notes"
            >
              <Textarea
                id="notes"
                rows={4}
                {...register('notes')}
                placeholder={t('sales:documents.notesPlaceholder', 'Additional notes...')}
              />
            </FormField>
          </div>
        </div>

        {/* Document Lines */}
        <DocumentLineEditor
          lines={lines}
          onChange={setLines}
          partnerId={watchedPartnerId || null}
          {...(effectiveType ? { documentType: effectiveType } : {})}
        />

        {/* Additional Costs (Purchase Orders only - after document has an autosaved draft id) */}
        {effectiveType === 'purchase_order' && docId && (
          <PurchaseOrderAdditionalCosts
            documentId={docId}
            disabled={additionalCostsDisabled}
            currency={currentCompany?.currency ?? 'TND'}
          />
        )}

        {/* Form Actions */}
        <StickyFormFooter>
          {/* Auto-save indicator (only for new documents) */}
          {!isEditing && (
            <div className={cn('mr-auto flex items-center gap-2 text-sm', textColors.tertiary)}>
              {isSaving ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  <span>{t('saving')}</span>
                </>
              ) : lastSavedAt ? (
                <>
                  <Check className={cn('h-4 w-4', textColors.success)} />
                  <span>
                    {t('sales:documents.draftSavedAt', { time: lastSavedAt.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' }) })}
                  </span>
                </>
              ) : null}
            </div>
          )}

          <Button
            type="button"
            variant="secondary"
            onClick={() => { if (!shouldWarn || confirmDiscard(t('confirmation.unsavedChangesBody'))) void navigate(basePath) }}
          >
            {t('actions.cancel')}
          </Button>
          <SaveSplitButton
            isPending={isSubmitInProgress}
            onPrimarySave={() => {}}
            onSaveAndClose={() => {
              closeIntentRef.current = true
              void handleSubmit(onSubmit, () => { closeIntentRef.current = false })()
            }}
          />
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
