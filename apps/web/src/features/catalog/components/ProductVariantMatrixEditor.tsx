import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { usePermissions } from '@/hooks/usePermissions'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { getErrorMessage } from '@/lib/api'
import {
  useAttributes,
  useDeleteVariant,
  useGenerateMatrix,
  useUpdateVariant,
  useVariantsForProduct,
} from '../hooks/useVariants'
import type { ProductVariant, UpdateVariantPayload } from '../api/variantApi'

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

export function ProductVariantMatrixEditor({ productId }: ProductVariantMatrixEditorProps) {
  const { t } = useTranslation()
  const { hasPermission } = usePermissions()

  const { data: attributes } = useAttributes()
  const { data: variants, isLoading, isError } = useVariantsForProduct(productId)
  const generateMatrix = useGenerateMatrix(productId)
  const updateVariant = useUpdateVariant(productId)
  const deleteVariant = useDeleteVariant(productId)

  const canGenerate = hasPermission('catalog.variants.create')
  const canUpdate = hasPermission('catalog.variants.update')
  const canDelete = hasPermission('catalog.variants.delete')

  const [selectedAxes, setSelectedAxes] = useState<string[]>([])
  // Unsaved per-variant edits, keyed by variant id. The effective draft shown in
  // each row is `toDraft(serverVariant)` merged with its override (if any), so
  // server refetches always re-baseline cleanly without a sync effect.
  const [overrides, setOverrides] = useState<Record<string, Partial<VariantDraft>>>({})

  const variantAxes = (attributes ?? []).filter((a) => a.is_variant_axis)

  const draftFor = (variant: ProductVariant): VariantDraft => ({
    ...toDraft(variant),
    ...overrides[variant.id],
  })

  const toggleAxis = (attributeId: string) => {
    setSelectedAxes((prev) =>
      prev.includes(attributeId)
        ? prev.filter((id) => id !== attributeId)
        : [...prev, attributeId],
    )
  }

  const updateDraft = (variantId: string, patch: Partial<VariantDraft>) => {
    setOverrides((prev) => ({
      ...prev,
      [variantId]: { ...prev[variantId], ...patch },
    }))
  }

  const clearOverride = (variantId: string) => {
    setOverrides((prev) =>
      Object.fromEntries(
        Object.entries(prev).filter(([key]) => key !== variantId),
      ),
    )
  }

  const handleGenerate = async () => {
    if (selectedAxes.length === 0) {
      toast.error(t('catalog:variants.noAxesSelected'))
      return
    }
    try {
      await generateMatrix.mutateAsync(selectedAxes)
      toast.success(t('catalog:variants.generated'))
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
      toast.success(t('catalog:variants.saved'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleDelete = async (variantId: string) => {
    try {
      await deleteVariant.mutateAsync(variantId)
      toast.success(t('catalog:variants.deleted'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const hasVariants = (variants ?? []).length > 0

  return (
    <div className="space-y-4">
      <div>
        <h3 className={`text-lg font-medium ${textColors.primary}`}>
          {t('catalog:variants.title')}
        </h3>
        <p className={`mt-1 text-sm ${textColors.tertiary}`}>
          {t('catalog:variants.subtitle')}
        </p>
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
            <div className="flex flex-wrap gap-3">
              {variantAxes.map((axis) => (
                <label key={axis.id} className="inline-flex items-center gap-2">
                  <input
                    type="checkbox"
                    className={tokens.checkbox.base}
                    checked={selectedAxes.includes(axis.id)}
                    onChange={() => { toggleAxis(axis.id) }}
                    aria-label={axis.name}
                  />
                  <span className={`text-sm ${textColors.secondary}`}>{axis.name}</span>
                </label>
              ))}
            </div>
          )}
          <button
            type="button"
            onClick={() => { void handleGenerate() }}
            disabled={generateMatrix.isPending}
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
          >
            {hasVariants
              ? t('catalog:variants.regenerate')
              : t('catalog:variants.generate')}
          </button>
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
                      </div>
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        aria-label={`${t('catalog:variants.sku')} ${variant.name_suffix}`}
                        className={tokens.input.base}
                        value={draft.sku}
                        disabled={!canUpdate}
                        onChange={(e) => { updateDraft(variant.id, { sku: e.target.value }) }}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        aria-label={`${t('catalog:variants.barcode')} ${variant.name_suffix}`}
                        className={tokens.input.base}
                        value={draft.barcode}
                        disabled={!canUpdate}
                        onChange={(e) => { updateDraft(variant.id, { barcode: e.target.value }) }}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        inputMode="decimal"
                        aria-label={`${t('catalog:variants.price')} ${variant.name_suffix}`}
                        className={tokens.input.base}
                        value={draft.price_override}
                        disabled={!canUpdate}
                        onChange={(e) => { updateDraft(variant.id, { price_override: e.target.value }) }}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <input
                        type="text"
                        inputMode="decimal"
                        aria-label={`${t('catalog:variants.cost')} ${variant.name_suffix}`}
                        title={t('catalog:variants.costAdvisory')}
                        className={tokens.input.base}
                        value={draft.cost_override}
                        disabled={!canUpdate}
                        onChange={(e) => { updateDraft(variant.id, { cost_override: e.target.value }) }}
                      />
                    </td>
                    <td className="px-3 py-2 text-center">
                      <input
                        type="checkbox"
                        aria-label={t('catalog:variants.active')}
                        className={tokens.checkbox.base}
                        checked={draft.is_active}
                        disabled={!canUpdate}
                        onChange={(e) => { updateDraft(variant.id, { is_active: e.target.checked }) }}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-2">
                        {canUpdate ? (
                          <button
                            type="button"
                            onClick={() => { void handleSave(variant) }}
                            disabled={updateVariant.isPending}
                            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                          >
                            {t('catalog:variants.save')}
                          </button>
                        ) : null}
                        {canDelete ? (
                          <button
                            type="button"
                            onClick={() => { void handleDelete(variant.id) }}
                            disabled={deleteVariant.isPending}
                            aria-label={`${t('catalog:variants.delete')} ${variant.name_suffix}`}
                            className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm} ${textColors.hoverError}`}
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
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
    </div>
  )
}
