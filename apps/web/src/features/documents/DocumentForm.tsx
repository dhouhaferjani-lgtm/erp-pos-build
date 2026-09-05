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
import { Button } from '../../components/atoms/Button/Button'
import { FormField } from '../../components/atoms/FormField/FormField'
import { Input } from '../../components/atoms/Input/Input'
import { Select } from '../../components/atoms/Select/Select'
import { Textarea } from '../../components/atoms/Textarea/Textarea'
import { PartnerPicker } from '../../components/molecules/pickers/PartnerPicker'
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
  partner_id: string | null
  issue_date: string
  due_date: string
  notes: string
  external_document_number: string
  external_document_date: string
  /** Credit notes only — `CreditNoteController::store()` requires it server-side. */
  reason: string
}

/**
 * Credit-note reasons, mirroring `CreditNoteReason` (PHP enum) and the option
 * list rendered by `CreateCreditNoteForm` (the invoice-linked modal) so both
 * credit-note entry points offer the exact same choices and i18n keys.
 */
const CREDIT_NOTE_REASONS: readonly { value: string; labelKey: string }[] = [
  { value: 'return', labelKey: 'sales:creditNotes.reason.return' },
  { value: 'price_adjustment', labelKey: 'sales:creditNotes.reason.priceAdjustment' },
  { value: 'billing_error', labelKey: 'sales:creditNotes.reason.billingError' },
  { value: 'damaged_goods', labelKey: 'sales:creditNotes.reason.damagedGoods' },
  { value: 'service_issue', labelKey: 'sales:creditNotes.reason.serviceIssue' },
  { value: 'other', labelKey: 'sales:creditNotes.reason.other' },
]

const documentTypeToPath: Record<DocumentType, string> = {
  quote: '/sales/quotes',
  sales_order: '/sales/orders',
  invoice: '/sales/invoices',
  purchase_order: '/purchases/orders',
  delivery_note: '/inventory/delivery-notes',
  credit_note: '/sales/credit-notes',
  return_note: '/inventory/return-notes',
}

// Translation keys for the singular document-type label rendered in the page
// heading. Using i18n keys (not hardcoded English) so the "Add <type>" /
// "Edit <type>" heading renders fully localized in every locale — previously
// this map held raw English, producing mixed headings under FR/AR
// (e.g. "Ajouter Purchase Order").
const documentTypeToTitleKey: Record<DocumentType, string> = {
  quote: 'sales:documents.types.quote',
  sales_order: 'sales:documents.types.sales_order',
  invoice: 'sales:documents.types.invoice',
  purchase_order: 'sales:documents.types.purchase_order',
  delivery_note: 'sales:documents.types.delivery_note',
  credit_note: 'sales:documents.types.credit_note',
  return_note: 'sales:documents.types.return_note',
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
 * Pure helper: normalises an API date to the `YYYY-MM-DD` value a
 * native HTML date input element accepts. The documents API emits plain dates
 * (`2026-08-01`) but some endpoints emit full ISO 8601 timestamps
 * (`2026-08-01T00:00:00+01:00`), which the date input silently rejects —
 * leaving a REQUIRED field empty and blocking submit.
 */
function toDateInputValue(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  return value.slice(0, 10)
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

import {
  buildAutoSaveLinePayload,
  buildLinePayload,
  findBlankPriceLineIds,
  hydrateLineUnitPrice,
} from './linePayload'


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
  // Lines the operator tried to save with no unit price. Populated only by a
  // submit attempt (so the cell is not scolded mid-typing) and cleared as soon
  // as the prices are filled in.
  const [blankPriceLineIds, setBlankPriceLineIds] = useState<ReadonlySet<string>>(() => new Set())
  // Track if URL partner has been applied
  const [hasAppliedUrlPartner, setHasAppliedUrlPartner] = useState(false)
  // Lines state (managed separately from form)
  const [lines, setLines] = useState<DocumentLine[]>([])
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
  const entityName = effectiveType
    ? t(documentTypeToTitleKey[effectiveType])
    : t('sales:documents.entityFallback', 'Document')

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
      partner_id: null,
      issue_date: new Date().toISOString().split('T')[0],
      due_date: '',
      notes: '',
      external_document_number: '',
      external_document_date: '',
      reason: '',
    },
  })

  const isCreditNote = effectiveType === 'credit_note'

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
      // N-1: the tax fields come from buildLinePayload now — an autosaved draft
      // must resolve tax the same way the saved document does, or the operator
      // sees one number while the draft holds another.
      lines: lines.map(line => ({
        id: line.id,
        ...buildAutoSaveLinePayload(line),
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

  // Populate form when document data loads.
  //
  // `issue_date` is the FORM field name; the canonical API field is
  // `document_date` (DocumentData::fromModel emits `document_date`, never
  // `issue_date` — only a few purchase endpoints use the `issue_date` alias).
  // Reading `document.issue_date` alone left the required Issue Date input
  // empty on EVERY document edit, which blocked the submit client-side
  // (money-campaign W1b MTP-DOC-07/10). Prefer the alias when a given endpoint
  // does provide it, then fall back to `document_date`, normalised to the
  // `YYYY-MM-DD` a native HTML date input element accepts.
  useEffect(() => {
    if (document) {
      reset({
        type: document.type as DocumentFormData['type'],
        partner_id: document.partner_id ?? null,
        issue_date: toDateInputValue(document.issue_date ?? document.document_date),
        due_date: toDateInputValue(document.due_date),
        notes: document.notes ?? '',
        external_document_number: document.external_document_number ?? '',
        external_document_date: toDateInputValue(document.external_document_date),
        reason: document.reason ?? '',
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
    const initialLines = document.lines.map((l): DocumentLine => ({
      id: l.id,
      product_id: l.product_id ?? '',
      ...(l.service_id !== null && l.service_id !== undefined ? { service_id: l.service_id } : {}),
      product_code: '',
      product_name: l.product_name,
      description: l.description,
      quantity: l.quantity,
      free_quantity: l.free_quantity ?? '0',
      free_quantity_received: l.free_quantity_received ?? '0',
      free_quantity_invoiced: l.free_quantity_invoiced ?? '0',
      unit_price: hydrateLineUnitPrice(l.unit_price, effectiveType),
      discount_percent: l.discount_percent ?? null,
      discount_amount: l.discount_amount ?? null,
      tax_rate: l.tax_rate ?? '0',
      line_total: l.line_total,
      price_entry_mode: l.price_entry_mode ?? 'unit',
      landed_unit_cost: l.landed_unit_cost ?? null,
      is_bonus_line: l.is_bonus_line ?? false,
      is_service: l.is_service ?? (l.service_id !== null && l.service_id !== undefined),
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
        queryClient.invalidateQueries({ queryKey: [effectiveType] }),
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
        queryClient.invalidateQueries({ queryKey: [effectiveType] }),
        queryClient.invalidateQueries({ queryKey: ['document', effectiveType, id] }),
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
    // Gate r1 finding 1: a line with no unit price must never reach the server.
    // On a purchase order it would confirm at 0.000 (PurchaseOrderService::confirm
    // has no zero-price guard), post Dr 37 at zero on receipt and drag WAC down,
    // with the three-way match only raising an ADVISORY variance under the
    // shipped `warn` policy. On a sales document it would bill zero.
    const blankPriceIds = findBlankPriceLineIds(lines)
    if (blankPriceIds.length > 0) {
      setBlankPriceLineIds(new Set(blankPriceIds))
      return
    }
    setBlankPriceLineIds(new Set())

    // `reason` is a credit-note-only field. Strip it everywhere else so the
    // other document endpoints (which don't declare it) never receive it.
    const { reason, ...rest } = data
    // Ensure the type is set from context if not in form
    const submitData = {
      ...rest,
      ...(isCreditNote ? { reason } : {}),
      type: data.type || effectiveType || '',
      lines: lines.map((line) => ({
        ...buildLinePayload(line),
        description: line.description,
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
            {/*
              `error` is REQUIRED here, not decorative: partner_id is a
              `required` RHF rule, and without the error prop a submit with no
              partner selected was a completely silent no-op — no message, no
              toast, no network request (money-campaign W1b defect 1's "zero
              feedback" half).
            */}
            <FormField
              label={partnerLabel}
              htmlFor="partner_id"
              required
              error={errors.partner_id?.message}
            >
              <Controller
                name="partner_id"
                control={control}
                rules={{
                  required: t('validation.required', 'This field is required'),
                }}
                render={({ field }) => (
                  <PartnerPicker
                    value={field.value ?? null}
                    onChange={(next) => { field.onChange(next?.id ?? null) }}
                    partnerType={partnerTypeFilter ?? 'all'}
                    label=""
                    placeholder={partnerLabel}
                    allowNewInline
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

            {/* Due Date — client-side mirror of the backend
                `after_or_equal:document_date` guard (DEV-QA-008/057). The
                backend remains authoritative; this only spares the operator a
                round-trip. */}
            <FormField
              label={t('sales:documents.dueDate')}
              htmlFor="due_date"
              error={errors.due_date?.message}
            >
              <Input
                type="date"
                id="due_date"
                min={watchedDocumentDate || undefined}
                error={Boolean(errors.due_date)}
                {...register('due_date', {
                  validate: value =>
                    !value ||
                    !watchedDocumentDate ||
                    value >= watchedDocumentDate ||
                    t('sales:documents.dueDateBeforeIssue'),
                })}
              />
            </FormField>

            {/* Reason (Credit Notes only) — `CreditNoteController::store()`
                validates `reason` as `['required', new Enum(CreditNoteReason::class)]`,
                so without this field a standalone credit note created here
                could only ever 422 (money-campaign W1b MTP-DOC-23). Same
                options and i18n keys as the invoice-linked modal
                (`CreateCreditNoteForm`). */}
            {isCreditNote && (
              <FormField
                label={t('sales:creditNotes.reason.title')}
                htmlFor="reason"
                required
                error={errors.reason?.message}
              >
                <Select
                  id="reason"
                  {...register('reason', { required: t('validation.required') })}
                  error={Boolean(errors.reason)}
                >
                  <option value="">{t('select')}</option>
                  {CREDIT_NOTE_REASONS.map((option) => (
                    <option key={option.value} value={option.value}>
                      {t(option.labelKey)}
                    </option>
                  ))}
                </Select>
              </FormField>
            )}

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
          onChange={(next) => {
            // Any line edit invalidates a stale blocked-submit flag; the next
            // submit re-derives it.
            setBlankPriceLineIds((current) => (current.size === 0 ? current : new Set()))
            setLines(next)
          }}
          invalidLineIds={blankPriceLineIds}
          partnerId={watchedPartnerId || null}
          {...(effectiveType ? { documentType: effectiveType } : {})}
        />

        {blankPriceLineIds.size > 0 && (
          <p role="alert" className={`text-sm ${textColors.error}`}>
            {t('sales:documents.errors.unitPriceRequired')}
          </p>
        )}

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
    </div>
  )
}
