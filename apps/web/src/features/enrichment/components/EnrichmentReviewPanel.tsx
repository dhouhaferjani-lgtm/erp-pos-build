import { useState, useMemo, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { toast } from 'sonner'
import { tokens, textColors, borderColors, colors } from '@/lib/designTokens'
import { Button, Textarea } from '@/components/atoms'
import { usePermissions, type Permission } from '@/hooks/usePermissions'
import {

  useEnrichmentResult,
  useAcceptEnrichment,
  useRejectEnrichment,
} from '../api/enrichmentQueries'
import { QualityBadge } from './QualityBadge'
import { FieldComparisonRow } from './FieldComparisonRow'
import type {
  EnrichmentRejectionReason,
  EnrichmentResult,
  ComparisonField,
} from '../types/enrichment'

interface EnrichmentReviewPanelProps {
  resultId: string
  onClose: () => void
}

function shouldFieldBeChecked(
  fieldKey: string,
  fieldConfidence: Record<string, number> | null,
  enrichmentTier: string | null,
): boolean {
  if (fieldConfidence && fieldKey in fieldConfidence) {
    const confidence = fieldConfidence[fieldKey]
    if (confidence >= 80) return true
    if (confidence >= 50 && (fieldKey === 'name' || fieldKey === 'brand')) return true
    return false
  }

  // Fall back to enrichment_tier
  if (enrichmentTier === 'high') return true
  if (enrichmentTier === 'medium' && (fieldKey === 'name' || fieldKey === 'brand')) return true
  return false
}

function buildComparisonFields(result: EnrichmentResult): ComparisonField[] {
  const { enriched_data, product_name, product_barcode, assigned_barcode } = result
  const fieldConfidence = enriched_data.field_confidence
  const enrichmentTier = enriched_data.enrichment_tier

  const fields: ComparisonField[] = []

  // name - always shown
  fields.push({
    key: 'name',
    label: 'Name',
    userValue: product_name,
    enrichedValue: enriched_data.name,
    confidence: fieldConfidence?.['name'] ?? null,
    checked: shouldFieldBeChecked('name', fieldConfidence, enrichmentTier),
  })

  // brand - shown if enriched_data.brand exists
  if (enriched_data.brand) {
    fields.push({
      key: 'brand',
      label: 'Brand',
      userValue: null,
      enrichedValue: enriched_data.brand,
      confidence: fieldConfidence?.['brand'] ?? null,
      checked: shouldFieldBeChecked('brand', fieldConfidence, enrichmentTier),
    })
  }

  // description - shown if enriched_data.description exists
  if (enriched_data.description) {
    fields.push({
      key: 'description',
      label: 'Description',
      userValue: null,
      enrichedValue: enriched_data.description,
      confidence: fieldConfidence?.['description'] ?? null,
      checked: shouldFieldBeChecked('description', fieldConfidence, enrichmentTier),
    })
  }

  // barcode - shown if assigned_barcode exists
  if (assigned_barcode) {
    fields.push({
      key: 'barcode',
      label: 'Barcode',
      userValue: product_barcode,
      enrichedValue: assigned_barcode,
      confidence: fieldConfidence?.['barcode'] ?? null,
      checked: shouldFieldBeChecked('barcode', fieldConfidence, enrichmentTier),
    })
  }

  return fields
}

export function EnrichmentReviewPanel({ resultId, onClose }: EnrichmentReviewPanelProps) {
  const { t } = useTranslation('enrichment')
  const { hasPermission } = usePermissions()
  const canReview = hasPermission('enrichment.review' as Permission)

  const { data: result, isLoading, isError } = useEnrichmentResult(resultId)
  const acceptMutation = useAcceptEnrichment()
  const rejectMutation = useRejectEnrichment()

  const [showRejectInput, setShowRejectInput] = useState(false)
  const [rejectReason, setRejectReason] = useState<EnrichmentRejectionReason | ''>('')
  const [rejectNotes, setRejectNotes] = useState('')

  const initialFields = useMemo(() => {
    if (!result) return []
    return buildComparisonFields(result)
  }, [result])

  const [checkedFields, setCheckedFields] = useState<Partial<Record<string, boolean>>>({})

  // Merge initial computed checks with user overrides
  const fields = useMemo(() => {
    return initialFields.map((f) => ({
      ...f,
      checked: checkedFields[f.key] ?? f.checked,
    }))
  }, [initialFields, checkedFields])

  const handleToggleField = useCallback((key: string) => {
    setCheckedFields((prev) => {
      const currentField = initialFields.find((f) => f.key === key)
      const currentChecked = prev[key] ?? currentField?.checked ?? false
      return { ...prev, [key]: !currentChecked }
    })
  }, [initialFields])

  const handleAccept = useCallback(() => {
    const acceptedFieldNames = fields.filter((f) => f.checked).map((f) => f.key)
    if (acceptedFieldNames.length === 0) {
      toast.error(t('review.noFieldsSelected'))
      return
    }
    acceptMutation.mutate(
      { id: resultId, acceptedFields: acceptedFieldNames },
      {
        onSuccess: () => {
          toast.success(t('review.acceptSuccess'))
          onClose()
        },
        onError: () => {
          toast.error(t('review.acceptError'))
        },
      },
    )
  }, [fields, resultId, acceptMutation, onClose, t])

  const handleReject = useCallback(() => {
    if (!showRejectInput) {
      setShowRejectInput(true)
      return
    }
    if (!rejectReason) {
      return
    }
    const trimmedNotes = rejectNotes.trim()
    rejectMutation.mutate(
      {
        id: resultId,
        ...(trimmedNotes ? { notes: trimmedNotes } : {}),
        reason: rejectReason,
      },
      {
        onSuccess: () => {
          toast.success(t('review.rejectSuccess'))
          onClose()
        },
        onError: () => {
          toast.error(t('review.rejectError'))
        },
      },
    )
  }, [showRejectInput, resultId, rejectReason, rejectNotes, rejectMutation, onClose, t])

  if (isLoading) {
    return (
      <div className={`fixed inset-y-0 right-0 z-50 w-[520px] ${colors.white} ${borderColors.light} border-l shadow-lg flex items-center justify-center`}>
        <div className={`text-sm ${textColors.secondary}`}>{t('review.loading')}</div>
      </div>
    )
  }

  if (isError || !result) {
    return (
      <div className={`fixed inset-y-0 right-0 z-50 w-[520px] ${colors.white} ${borderColors.light} border-l shadow-lg flex items-center justify-center`}>
        <div className={`text-sm ${textColors.error}`}>{t('review.loadError')}</div>
      </div>
    )
  }

  return (
    <div className={`fixed inset-y-0 right-0 z-50 w-[520px] ${colors.white} ${borderColors.light} border-l shadow-lg flex flex-col`}>
      {/* Header */}
      <div className="flex items-start justify-between px-5 py-4 border-b">
        <div className="flex-1 min-w-0">
          <h2 className={`text-lg font-semibold ${textColors.primary} truncate`}>
            {result.product_name}
          </h2>
          <div className="flex items-center gap-2 mt-1">
            {result.product_barcode && (
              <span className={`text-xs font-mono ${textColors.tertiary}`}>
                {result.product_barcode}
              </span>
            )}
            <QualityBadge quality={result.enrichment_quality} />
            {result.origin === 'curated_update' && (
              <span className={`${tokens.badge.base} ${tokens.badge.blue}`}>
                {t('review.curatedUpdateBadge')}
              </span>
            )}
          </div>
        </div>
        <button
          type="button"
          onClick={onClose}
          className={`${tokens.modal.closeButton} ml-2 flex-shrink-0`}
          aria-label={t('review.close')}
        >
          <X className="h-5 w-5" />
        </button>
      </div>

      {/* Assigned barcode notice */}
      {result.assigned_barcode && (
        <div className={`${tokens.alert.info} mx-5 mt-3`}>
          {t('review.assignedBarcodeNotice', { barcode: result.assigned_barcode })}
        </div>
      )}

      {/* Scrollable content */}
      <div className="flex-1 overflow-y-auto">
        {/* Column headers */}
        <div className="grid grid-cols-2 gap-0 sticky top-0 z-10">
          <div className={`${colors.neutral[50]} px-3.5 py-2 text-xs font-medium ${textColors.secondary} border-b`}>
            {t('review.yourData')}
          </div>
          <div className={`${colors.success[50]} px-3.5 py-2 text-xs font-medium ${textColors.success} border-b`}>
            {t('review.enrichedData')}
          </div>
        </div>

        {/* Field comparison rows */}
        {fields.map((field) => (
          <FieldComparisonRow
            key={field.key}
            label={t(`review.fields.${field.key}`, { defaultValue: field.label })}
            userValue={field.userValue}
            enrichedValue={field.enrichedValue}
            checked={field.checked}
            onToggle={() => {
              handleToggleField(field.key)
            }}
            highlight={field.checked}
          />
        ))}

        {/* Checkbox hint */}
        <div className={`px-5 py-3 text-xs ${textColors.tertiary}`}>
          {t('review.checkboxHint')}
        </div>
      </div>

      {/* Footer with action buttons */}
      {canReview && (
        <div className="border-t px-5 py-4 space-y-3">
          {showRejectInput && (
            <div className="space-y-3">
              <fieldset className="space-y-2">
                <legend className={`text-sm font-medium ${textColors.secondary}`}>
                  {t('review.rejectReasonLabel')}
                </legend>
                <div className="grid grid-cols-2 gap-2">
                  {([
                    ['wrong_product', t('review.rejectReasons.wrongProduct')],
                    ['bad_data', t('review.rejectReasons.badData')],
                  ] as const).map(([value, label]) => (
                    <label
                      key={value}
                      className={`flex cursor-pointer items-center gap-2 rounded-md border ${borderColors.light} px-3 py-2 text-sm ${textColors.primary}`}
                    >
                      {/* No Radio atom exists — inline tokens.radio.base preserves styling */}
                      <input
                        type="radio"
                        name="reject-reason"
                        value={value}
                        checked={rejectReason === value}
                        onChange={() => {
                          setRejectReason(value)
                        }}
                        className={tokens.radio.base}
                      />
                      <span>{label}</span>
                    </label>
                  ))}
                </div>
              </fieldset>
              <label className="block">
                <span className={`text-sm font-medium ${textColors.secondary}`}>
                  {t('review.rejectNoteLabel')}
                </span>
                <Textarea
                  value={rejectNotes}
                  onChange={(e) => {
                    setRejectNotes(e.target.value)
                  }}
                  placeholder={t('review.rejectNotePlaceholder')}
                  className="min-h-20"
                />
              </label>
            </div>
          )}
          <div className="flex gap-3">
            <Button
              type="button"
              variant="danger"
              onClick={handleReject}
              disabled={rejectMutation.isPending || (showRejectInput && !rejectReason)}
              className="flex-1"
            >
              {rejectMutation.isPending
                ? t('review.rejecting')
                : showRejectInput
                  ? t('review.confirmReject')
                  : t('review.reject')}
            </Button>
            <Button
              type="button"
              onClick={handleAccept}
              disabled={acceptMutation.isPending}
              className="flex-1"
            >
              {acceptMutation.isPending ? t('review.accepting') : t('review.accept')}
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
