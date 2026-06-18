import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { usePermissions } from '@/hooks/usePermissions'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { Button, Checkbox, Input, MoneyInput } from '@/components/atoms'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useCompanyConfig } from '@/contexts'
import { getErrorMessage } from '@/lib/api'
import {
  useAttributes,
  useAttributeValues,
  useDeleteVariant,
  useGenerateMatrix,
  useUpdateVariant,
  useVariantsForProduct,
} from '../hooks/useVariants'
import { VariantLabelDialog } from './VariantLabelDialog'
import type {
  GenerateMatrixAxis,
  ProductVariant,
  UpdateVariantPayload,
} from '../api/variantApi'

/**
 * Soft warning + hard cap on the generated combination count. These mirror the
 * backend's `generate-matrix` guards (spec §6.4): above the soft threshold we
 * warn the user, and above the hard cap we refuse to submit (the backend would
 * reject it anyway).
 */
export const VARIANT_COUNT_SOFT_WARN = 50
export const MAX_VARIANTS_PER_GENERATE = 200

interface ProductVariantMatrixEditorProps {
  productId: string
}

/**
 * Editable per-variant draft. Mirrors the writable fields of the Task 28
 * PATCH `/product-variants/{id}` endpoint. `cost_override` is advisory only
 * (spec §6.7) — it is persisted for display and never feeds the inventory WAC
 * pipeline. Stock quantity is NOT edited here: it is product/variant-grain
 * inventory managed through the inventory stock-levels surface, not the catalog
 * variant DTO.
 */
interface VariantDraft {
  sku: string
  barcode: string
  price_override: string
  cost_override: string
  image_url: string
  is_active: boolean
}

function toDraft(variant: ProductVariant): VariantDraft {
  return {
    sku: variant.sku,
    barcode: variant.barcode ?? '',
    price_override: variant.price_override ?? '',
    cost_override: variant.cost_override ?? '',
    image_url: variant.image_url ?? '',
    is_active: variant.is_active,
  }
}

function draftToPayload(draft: VariantDraft): UpdateVariantPayload {
  return {
    sku: draft.sku.trim(),
    barcode: draft.barcode.trim() === '' ? null : draft.barcode.trim(),
    price_override: draft.price_override.trim() === '' ? null : draft.price_override.trim(),
    cost_override: draft.cost_override.trim() === '' ? null : draft.cost_override.trim(),
    image_url: draft.image_url.trim() === '' ? null : draft.image_url.trim(),
    is_active: draft.is_active,
  }
}

/**
 * Extract the `barcode` validation message from a Laravel 422 response. The
 * backend wraps a `ValidationException` as `{ message, errors: { barcode: [...] } }`;
 * some envelopes additionally nest the body under `error`. We probe both shapes
 * and return the first barcode message, or null when none is present.
 */
function barcodeValidationMessage(error: unknown): string | null {
  if (typeof error !== 'object' || error === null) return null
  const response = (error as { response?: { data?: unknown } }).response
  const data = response?.data
  if (typeof data !== 'object' || data === null) return null

  const candidates: unknown[] = [
    (data as { errors?: { barcode?: unknown } }).errors?.barcode,
    (data as { error?: { errors?: { barcode?: unknown } } }).error?.errors?.barcode,
  ]
  for (const candidate of candidates) {
    if (Array.isArray(candidate) && typeof candidate[0] === 'string') {
      return candidate[0]
    }
  }
  return null
}

/**
 * Renders the value chips for a single CHECKED axis. Pulled into its own
 * component so `useAttributeValues` is never called inside a `.map()` (Rules of
 * Hooks). Once values load it seeds the parent selection with ALL of the axis's
 * values via `onSeedValues` (guarded one-shot in the parent).
 */
function AxisValueChips({
  attributeId,
  selectedValueIds,
  onToggleValue,
  onSeedValues,
}: {
  attributeId: string
  selectedValueIds: string[]
  onToggleValue: (attributeId: string, valueId: string) => void
  onSeedValues: (attributeId: string, valueIds: string[]) => void
}) {
  const { t } = useTranslation()
  const { data: values } = useAttributeValues(attributeId)

  useEffect(() => {
    if (values && values.length > 0) {
      onSeedValues(
        attributeId,
        values.map((v) => v.id),
      )
    }
  }, [values, attributeId, onSeedValues])

  return (
    <div className="flex flex-wrap gap-2">
      {(values ?? []).map((v) => (
        <label key={v.id} className="inline-flex items-center gap-1">
          <Checkbox
            aria-label={t('catalog:variants.valueLabel', { label: v.label })}
            checked={selectedValueIds.includes(v.id)}
            onChange={() => {
              onToggleValue(attributeId, v.id)
            }}
          />
          {v.hex_color ? (
            <span
              style={{ backgroundColor: v.hex_color }}
              className="inline-block h-3 w-3 rounded-full"
            />
          ) : null}
          <span className={`text-sm ${textColors.secondary}`}>{v.label}</span>
        </label>
      ))}
    </div>
  )
}

export function ProductVariantMatrixEditor({ productId }: ProductVariantMatrixEditorProps) {
  const { t } = useTranslation()
  const { hasPermission } = usePermissions()
  const { config } = useCompanyConfig()
  const currency = config?.currency ?? 'TND'

  const { data: attributes } = useAttributes()
  const { data: variants, isLoading, isError } = useVariantsForProduct(productId)
  const generateMatrix = useGenerateMatrix(productId)
  const updateVariant = useUpdateVariant(productId)
  const deleteVariant = useDeleteVariant(productId)

  const canGenerate = hasPermission('catalog.variants.create')
  const canUpdate = hasPermission('catalog.variants.update')
  const canDelete = hasPermission('catalog.variants.delete')
  const canPrintLabels = hasPermission('catalog.labels.print')

  // Whether the variant-label printing dialog is open. Seeded with the
  // product's variants (id + name_suffix) so the user can choose quantities.
  const [labelDialogOpen, setLabelDialogOpen] = useState(false)

  // Which value ids are selected per attribute axis. An axis key present here is
  // a "checked" axis; its array is the subset of value ids that go into the
  // cartesian product. Removing the key unchecks the axis entirely.
  const [selectedValues, setSelectedValues] = useState<Record<string, string[]>>({})
  // Unsaved per-variant edits, keyed by variant id. The effective draft shown in
  // each row is `toDraft(serverVariant)` merged with its override (if any), so
  // server refetches always re-baseline cleanly without a sync effect.
  const [overrides, setOverrides] = useState<Record<string, Partial<VariantDraft>>>({})
  // Inline per-row barcode validation errors (from a 422 on save).
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({})
  // Variant pending delete confirmation, and whether the dialog has flipped to
  // the "has stock → deactivate" body after a 422.
  const [deleteTarget, setDeleteTarget] = useState<ProductVariant | null>(null)
  const [deleteHasStock, setDeleteHasStock] = useState(false)

  // Axes that have already been seeded (default-all on first value-load, or via
  // hydration from existing variants). Seeding happens exactly ONCE per axis, so
  // a user who deselects all of an axis's values is never auto-refilled.
  const seededAxesRef = useRef<Set<string>>(new Set())

  const variantAxes = (attributes ?? []).filter((a) => a.is_variant_axis)

  const draftFor = (variant: ProductVariant): VariantDraft => ({
    ...toDraft(variant),
    ...overrides[variant.id],
  })

  // --- Axis / value selection -------------------------------------------------

  const isAxisChecked = (attributeId: string) =>
    Object.prototype.hasOwnProperty.call(selectedValues, attributeId)

  const toggleAxis = (attributeId: string) => {
    setSelectedValues((prev) => {
      if (Object.prototype.hasOwnProperty.call(prev, attributeId)) {
        const next = { ...prev }
        delete next[attributeId]
        return next
      }
      // Seed empty; AxisValueChips' effect fills in all values once they load.
      return { ...prev, [attributeId]: [] }
    })
  }

  // Memoized so the child effect (deps include this callback) does not re-fire on
  // every parent render. The "seed once per axis" guard keys on a ref Set rather
  // than the current length, so deselecting all values does NOT re-trigger a
  // refill. Only uses the functional-updater form of setSelectedValues + the
  // ref, so empty deps are correct and stable.
  const seedAxisValues = useCallback((attributeId: string, valueIds: string[]) => {
    if (seededAxesRef.current.has(attributeId)) return
    seededAxesRef.current.add(attributeId)
    setSelectedValues((prev) => {
      // Only seed when the axis is checked (its key is present). If hydration
      // already populated it, leave that selection untouched.
      if (
        !Object.prototype.hasOwnProperty.call(prev, attributeId) ||
        prev[attributeId].length > 0
      ) {
        return prev
      }
      return { ...prev, [attributeId]: valueIds }
    })
  }, [])

  const toggleValue = (attributeId: string, valueId: string) => {
    setSelectedValues((prev) => {
      const current = prev[attributeId] ?? []
      const next = current.includes(valueId)
        ? current.filter((id) => id !== valueId)
        : [...current, valueId]
      return { ...prev, [attributeId]: next }
    })
  }

  // Hydrate the initial selection from the union of existing variants'
  // attribute_values (group value ids by attribute id), seeded ONCE when the
  // variants first load — mirrors ProductForm's variantsToggleSeededRef guard.
  const hydratedRef = useRef(false)
  useEffect(() => {
    if (hydratedRef.current) return
    const list = variants ?? []
    if (list.length === 0) return
    hydratedRef.current = true
    const grouped: Record<string, string[]> = {}
    for (const variant of list) {
      for (const pair of variant.attribute_values ?? []) {
        const existing = grouped[pair.attribute_id] ?? []
        if (!existing.includes(pair.attribute_value_id)) {
          grouped[pair.attribute_id] = [...existing, pair.attribute_value_id]
        }
      }
    }
    if (Object.keys(grouped).length > 0) {
      // Mark hydrated axes as seeded so the default-all seed in AxisValueChips
      // doesn't fight hydration (e.g. refill values the user intentionally
      // didn't include / later deselects).
      for (const attributeId of Object.keys(grouped)) {
        seededAxesRef.current.add(attributeId)
      }
      setSelectedValues(grouped)
    }
  }, [variants])

  // comboCount = product of the per-axis selected value counts. With zero axes
  // selected there is nothing to generate, so it is 0 (not 1).
  const axisCounts = Object.values(selectedValues).map((ids) => ids.length)
  const comboCount =
    axisCounts.length === 0 ? 0 : axisCounts.reduce((n, count) => n * count, 1)

  const overSoftWarn = comboCount >= VARIANT_COUNT_SOFT_WARN
  const overHardCap = comboCount > MAX_VARIANTS_PER_GENERATE

  // --- Draft / row edit helpers ----------------------------------------------

  const updateDraft = (variantId: string, patch: Partial<VariantDraft>) => {
    setOverrides((prev) => ({
      ...prev,
      [variantId]: { ...prev[variantId], ...patch },
    }))
    // Editing the row clears any stale inline error.
    setRowErrors((prev) => {
      if (!(variantId in prev)) return prev
      const next = { ...prev }
      delete next[variantId]
      return next
    })
  }

  const clearOverride = (variantId: string) => {
    setOverrides((prev) =>
      Object.fromEntries(Object.entries(prev).filter(([key]) => key !== variantId)),
    )
  }

  // --- Orphan detection -------------------------------------------------------

  // A variant is an orphan when its combination is not a subset of the current
  // selection: any of its attribute_value pairs is missing from selectedValues.
  const isOrphan = (variant: ProductVariant): boolean => {
    const pairs = variant.attribute_values ?? []
    if (pairs.length === 0) return false
    return pairs.some((pair) => {
      const selectedForAxis = selectedValues[pair.attribute_id]
      return (
        selectedForAxis === undefined ||
        !selectedForAxis.includes(pair.attribute_value_id)
      )
    })
  }

  // --- Actions ----------------------------------------------------------------

  const handleGenerate = async () => {
    const axes: GenerateMatrixAxis[] = Object.entries(selectedValues)
      .filter(([, valueIds]) => valueIds.length > 0)
      .map(([attribute_id, value_ids]) => ({ attribute_id, value_ids }))

    if (axes.length === 0) {
      toast.error(t('catalog:variants.noAxesSelected'))
      return
    }
    if (overHardCap) {
      toast.error(
        t('catalog:variants.tooMany', { max: MAX_VARIANTS_PER_GENERATE }),
      )
      return
    }
    try {
      const result = await generateMatrix.mutateAsync(axes)
      toast.success(
        t('catalog:variants.generatedSummary', {
          created: result.meta.created_count,
          skipped: result.meta.skipped_count,
          restored: result.meta.restored_count,
        }),
      )
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleSave = async (variant: ProductVariant) => {
    try {
      await updateVariant.mutateAsync({
        variantId: variant.id,
        payload: draftToPayload(draftFor(variant)),
      })
      clearOverride(variant.id)
      setRowErrors((prev) => {
        if (!(variant.id in prev)) return prev
        const next = { ...prev }
        delete next[variant.id]
        return next
      })
      toast.success(t('catalog:variants.saved'))
    } catch (error) {
      const barcodeMessage = barcodeValidationMessage(error)
      if (barcodeMessage !== null) {
        setRowErrors((prev) => ({ ...prev, [variant.id]: barcodeMessage }))
        return
      }
      toast.error(getErrorMessage(error))
    }
  }

  const openDeleteDialog = (variant: ProductVariant) => {
    setDeleteTarget(variant)
    setDeleteHasStock(false)
  }

  const closeDeleteDialog = () => {
    setDeleteTarget(null)
    setDeleteHasStock(false)
  }

  const handleConfirmDelete = async () => {
    if (!deleteTarget) return
    try {
      await deleteVariant.mutateAsync(deleteTarget.id)
      toast.success(t('catalog:variants.deleted'))
      closeDeleteDialog()
    } catch (error) {
      // 422 means the variant has on-hand stock and cannot be deleted; flip the
      // dialog to offer deactivation instead.
      const response = (error as { response?: { status?: number } }).response
      if (response?.status === 422) {
        setDeleteHasStock(true)
        return
      }
      toast.error(getErrorMessage(error))
      closeDeleteDialog()
    }
  }

  const handleDeactivate = async () => {
    if (!deleteTarget) return
    try {
      await updateVariant.mutateAsync({
        variantId: deleteTarget.id,
        payload: { is_active: false },
      })
      toast.success(t('catalog:variants.deactivated'))
      closeDeleteDialog()
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const hasVariants = (variants ?? []).length > 0

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h3 className={tokens.heading.section}>{t('catalog:variants.title')}</h3>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('catalog:variants.subtitle')}
          </p>
        </div>
        {canPrintLabels && hasVariants ? (
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => {
              setLabelDialogOpen(true)
            }}
          >
            {t('catalog:labels.printLabels')}
          </Button>
        ) : null}
      </div>

      {canGenerate ? (
        <div className={`${tokens.card.base} space-y-3`}>
          <p className={`text-sm font-medium ${textColors.secondary}`}>
            {t('catalog:variants.selectAxes')}
          </p>
          {variantAxes.length === 0 ? (
            <p className={`text-sm ${textColors.tertiary}`}>
              {t('catalog:attributes.empty')}
            </p>
          ) : (
            <div className="space-y-3">
              {variantAxes.map((axis) => (
                <div key={axis.id} className="space-y-1">
                  <label className="inline-flex items-center gap-2">
                    <Checkbox
                      checked={isAxisChecked(axis.id)}
                      onChange={() => {
                        toggleAxis(axis.id)
                      }}
                      aria-label={axis.name}
                    />
                    <span className={`text-sm font-medium ${textColors.secondary}`}>
                      {axis.name}
                    </span>
                  </label>
                  {isAxisChecked(axis.id) ? (
                    <div className="ps-6">
                      <AxisValueChips
                        attributeId={axis.id}
                        selectedValueIds={selectedValues[axis.id] ?? []}
                        onToggleValue={toggleValue}
                        onSeedValues={seedAxisValues}
                      />
                    </div>
                  ) : null}
                </div>
              ))}
            </div>
          )}

          <p
            className={`text-sm ${overSoftWarn ? textColors.warning : textColors.tertiary}`}
          >
            {t('catalog:variants.comboCount', { count: comboCount })}
          </p>
          {overHardCap ? (
            <p className={`text-sm ${textColors.error}`}>
              {t('catalog:variants.tooMany', { max: MAX_VARIANTS_PER_GENERATE })}
            </p>
          ) : null}

          <Button
            type="button"
            onClick={() => {
              void handleGenerate()
            }}
            disabled={generateMatrix.isPending || overHardCap}
          >
            {hasVariants
              ? t('catalog:variants.regenerate')
              : t('catalog:variants.generate')}
          </Button>
        </div>
      ) : null}

      {isError ? (
        <div className={`${tokens.alert.base} ${tokens.alert.error}`}>
          {t('catalog:variants.loadError')}
        </div>
      ) : isLoading ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('common:loading')}</p>
      ) : !hasVariants ? (
        <div className={`${tokens.card.base} text-center`}>
          <p className={`text-sm ${textColors.tertiary}`}>
            {t('catalog:variants.noVariants')}
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead>
              <tr className={`border-b text-left ${borderColors.light} ${textColors.tertiary}`}>
                <th className="px-3 py-2 font-medium">{t('catalog:variants.variant')}</th>
                <th className="px-3 py-2 font-medium">{t('catalog:variants.sku')}</th>
                <th className="px-3 py-2 font-medium">{t('catalog:variants.barcode')}</th>
                <th className="px-3 py-2 font-medium">{t('catalog:variants.price')}</th>
                <th className="px-3 py-2 font-medium">{t('catalog:variants.cost')}</th>
                <th className="px-3 py-2 font-medium">{t('catalog:variants.active')}</th>
                <th className="px-3 py-2 font-medium">{t('catalog:variants.actionsColumn')}</th>
              </tr>
            </thead>
            <tbody className={`divide-y ${borderColors.divideLight}`}>
              {(variants ?? []).map((variant) => {
                const draft = draftFor(variant)
                const rowError = rowErrors[variant.id]
                return (
                  <tr key={variant.id}>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-2">
                        <span className={textColors.primary}>{variant.name_suffix}</span>
                        {variant.is_default ? (
                          <span className={`${tokens.badge.base} ${tokens.badge.gray}`}>
                            {t('catalog:variants.default')}
                          </span>
                        ) : null}
                        {isOrphan(variant) ? (
                          <span className={`${tokens.badge.base} ${tokens.badge.yellow}`}>
                            {t('catalog:variants.orphan')}
                          </span>
                        ) : null}
                      </div>
                    </td>
                    <td className="px-3 py-2">
                      <Input
                        type="text"
                        aria-label={`${t('catalog:variants.sku')} ${variant.name_suffix}`}
                        value={draft.sku}
                        disabled={!canUpdate}
                        onChange={(e) => {
                          updateDraft(variant.id, { sku: e.target.value })
                        }}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <Input
                        type="text"
                        aria-label={`${t('catalog:variants.barcode')} ${variant.name_suffix}`}
                        value={draft.barcode}
                        disabled={!canUpdate}
                        onChange={(e) => {
                          updateDraft(variant.id, { barcode: e.target.value })
                        }}
                      />
                      {rowError ? (
                        <p className={`mt-1 text-xs ${textColors.error}`}>{rowError}</p>
                      ) : null}
                    </td>
                    <td className="px-3 py-2">
                      <MoneyInput
                        currency={currency}
                        aria-label={`${t('catalog:variants.price')} ${variant.name_suffix}`}
                        value={draft.price_override}
                        disabled={!canUpdate}
                        onChange={(value) => {
                          updateDraft(variant.id, { price_override: value })
                        }}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <MoneyInput
                        currency={currency}
                        aria-label={`${t('catalog:variants.cost')} ${variant.name_suffix}`}
                        title={t('catalog:variants.costAdvisory')}
                        value={draft.cost_override}
                        disabled={!canUpdate}
                        onChange={(value) => {
                          updateDraft(variant.id, { cost_override: value })
                        }}
                      />
                    </td>
                    <td className="px-3 py-2 text-center">
                      <Checkbox
                        aria-label={t('catalog:variants.active')}
                        checked={draft.is_active}
                        disabled={!canUpdate}
                        onChange={(e) => {
                          updateDraft(variant.id, { is_active: e.target.checked })
                        }}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-2">
                        {canUpdate ? (
                          <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={() => {
                              void handleSave(variant)
                            }}
                            disabled={updateVariant.isPending}
                          >
                            {t('catalog:variants.save')}
                          </Button>
                        ) : null}
                        {canDelete ? (
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                              openDeleteDialog(variant)
                            }}
                            disabled={deleteVariant.isPending}
                            aria-label={`${t('catalog:variants.delete')} ${variant.name_suffix}`}
                            className={textColors.hoverError}
                          >
                            <Trash2 className="h-4 w-4" />
                          </Button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        onClose={closeDeleteDialog}
        onConfirm={() => {
          void (deleteHasStock ? handleDeactivate() : handleConfirmDelete())
        }}
        title={
          deleteHasStock
            ? t('catalog:variants.deactivateTitle')
            : t('catalog:variants.deleteTitle')
        }
        message={
          deleteHasStock
            ? t('catalog:variants.deactivateHasStockMessage', {
                name: deleteTarget?.name_suffix ?? '',
              })
            : t('catalog:variants.deleteMessage', {
                name: deleteTarget?.name_suffix ?? '',
              })
        }
        confirmText={
          deleteHasStock
            ? t('catalog:variants.deactivate')
            : t('catalog:variants.delete')
        }
        variant={deleteHasStock ? 'warning' : 'danger'}
        isLoading={deleteVariant.isPending || updateVariant.isPending}
      />

      {canPrintLabels ? (
        <VariantLabelDialog
          open={labelDialogOpen}
          onClose={() => {
            setLabelDialogOpen(false)
          }}
          variants={(variants ?? []).map((v) => ({
            id: v.id,
            name_suffix: v.name_suffix,
          }))}
        />
      ) : null}
    </div>
  )
}
