import { Controller } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { Button } from '@/components/atoms/Button/Button'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Input } from '@/components/atoms/Input/Input'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { Toggle } from '@/components/atoms/Toggle/Toggle'
import { ProductStockLevels } from '@/features/inventory/components/ProductStockLevels'
import { textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { EditorSectionCard } from '../editor/components/EditorSectionCard'
import type { ProductSectionsEditAdapter, ProductSectionsViewAdapter } from './types'

export type ProductInventoryAdapter =
  | Pick<
      ProductSectionsViewAdapter,
      'mode' | 'canViewCostPrices' | 'costPrices' | 'currency' | 'locale' | 'product'
    >
  | Pick<ProductSectionsEditAdapter, 'mode' | 'form' | 'inventory'>

export function ProductInventorySection({ adapter }: { adapter: ProductInventoryAdapter }) {
  const { t } = useTranslation(['catalog', 'inventory'])

  if (adapter.mode === 'view') {
    return (
      <EditorSectionCard
        id="section-inventory"
        title={t('catalog:editor.sectionLabels.inventory')}
        contentClassName="block"
      >
        <ProductStockLevels
          productId={adapter.product.id}
          costPrice={adapter.canViewCostPrices ? adapter.costPrices?.costPrice ?? null : null}
          canViewCostPrices={adapter.canViewCostPrices}
          currency={adapter.currency}
          locale={adapter.locale}
          embedded
        />
      </EditorSectionCard>
    )
  }

  const { form, inventory } = adapter
  const requiresBatchTracking = form.watch('requires_batch_tracking')
  const purchasePrice = form.watch('purchase_price')

  return (
    <EditorSectionCard
      id="section-inventory"
      title={t('catalog:editor.sectionLabels.inventory')}
    >
      <div className="sm:col-span-2">
        <FormField label={t('inventory:products.openingQtyShort')} htmlFor="opening_qty">
          {inventory.showOpeningSection && inventory.canEnterOpening ? (
            <Controller
              name="opening_qty"
              control={form.control}
              render={({ field }) => (
                <QuantityInput
                  id="opening_qty"
                  data-testid="opening-qty-input"
                  decimalPlaces={4}
                  value={field.value ?? ''}
                  onChange={(value) => {
                    field.onChange(value)
                    if (
                      value.trim() !== '' &&
                      value.trim() !== '0' &&
                      form.watch('opening_unit_cost').trim() === ''
                    ) {
                      form.setValue('opening_unit_cost', purchasePrice, { shouldDirty: true })
                    }
                  }}
                  onBlur={field.onBlur}
                />
              )}
            />
          ) : (
            <div className={cn('space-y-1 text-sm font-semibold', textColors.primary)}>
              <div>
                {t('inventory:products.onHandShort')}: {inventory.productStockQuantity ?? '0.0000'}
              </div>
              {inventory.isOpeningLocked && inventory.canResetOpening && !inventory.showResetConfirm && (
                <button
                  type="button"
                  data-testid="opening-reset-btn"
                  onClick={inventory.requestOpeningReset}
                  className={cn('text-xs font-medium', textColors.brand)}
                >
                  {t('inventory:opening.reset_label')}
                </button>
              )}
              {inventory.isOpeningLocked && inventory.canResetOpening && inventory.showResetConfirm && (
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="secondary" size="sm" onClick={inventory.cancelOpeningReset}>
                    {t('inventory:opening.reset_confirm_cancel')}
                  </Button>
                  <Button
                    type="button"
                    variant="danger"
                    size="sm"
                    disabled={inventory.isResettingOpening}
                    onClick={() => { void inventory.resetOpening() }}
                  >
                    {inventory.isResettingOpening
                      ? t('inventory:status.saving')
                      : t('inventory:opening.reset_confirm_proceed')}
                  </Button>
                </div>
              )}
              {inventory.isOpeningLocked && !inventory.canResetOpening && (
                <Link to="/inventory/stock" className={cn('text-xs font-medium', textColors.brand)}>
                  {t('inventory:opening.view_stock_link')}
                </Link>
              )}
            </div>
          )}
        </FormField>
      </div>

      <FormField label={t('inventory:products.unitsPerPack')} htmlFor="units_per_pack">
        <Input
          type="number"
          id="units_per_pack"
          min={1}
          placeholder={t('inventory:products.unitsPerPackPlaceholder')}
          {...form.register('units_per_pack', {
            setValueAs: (value: string | null): number | null =>
              value === '' || value === null ? null : Number(value),
          })}
        />
      </FormField>

      <FormField label={t('inventory:products.shelfLocation')} htmlFor="shelf_location">
        <Input
          type="text"
          id="shelf_location"
          placeholder={t('inventory:products.shelfLocationPlaceholder')}
          {...form.register('shelf_location')}
        />
      </FormField>

      <FormField label={t('inventory:products.reorderPoint')} htmlFor="reorder_point">
        <Controller
          name="reorder_point"
          control={form.control}
          render={({ field }) => (
            <QuantityInput
              id="reorder_point"
              decimalPlaces={inventory.reorderDecimals}
              value={field.value ?? ''}
              onChange={field.onChange}
              onBlur={field.onBlur}
            />
          )}
        />
      </FormField>

      <FormField label={t('inventory:products.reorderQuantity')} htmlFor="reorder_quantity">
        <Controller
          name="reorder_quantity"
          control={form.control}
          render={({ field }) => (
            <QuantityInput
              id="reorder_quantity"
              decimalPlaces={inventory.reorderDecimals}
              value={field.value ?? ''}
              onChange={field.onChange}
              onBlur={field.onBlur}
            />
          )}
        />
      </FormField>

      {inventory.showBatchTracking && (
        <div data-testid="batch-tracking-section">
          <div className="mt-6 flex items-center gap-2">
            <Controller
              name="requires_batch_tracking"
              control={form.control}
              render={({ field }) => (
                <Toggle
                  aria-label={t('inventory:products.requiresBatchTracking')}
                  label={t('inventory:products.requiresBatchTracking')}
                  checked={field.value}
                  onChange={(event) => { field.onChange(event.target.checked) }}
                  ref={field.ref}
                />
              )}
            />
          </div>
          <p className={tokens.helperText.base}>{t('inventory:products.requiresBatchTrackingHelper')}</p>

          {requiresBatchTracking && (
            <FormField
              label={t('inventory:products.defaultShelfLifeDays')}
              htmlFor="default_shelf_life_days"
              className="mt-3"
            >
              <Input
                type="number"
                id="default_shelf_life_days"
                min={0}
                placeholder={t('inventory:products.defaultShelfLifeDaysPlaceholder')}
                {...form.register('default_shelf_life_days', {
                  setValueAs: (value: string | null): number | null =>
                    value === '' || value === null ? null : Number(value),
                })}
              />
            </FormField>
          )}
        </div>
      )}
    </EditorSectionCard>
  )
}
