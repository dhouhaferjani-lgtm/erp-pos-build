import { Controller } from 'react-hook-form'
import { useTranslation } from 'react-i18next'

import { FormField } from '@/components/atoms/FormField/FormField'
import { Input } from '@/components/atoms/Input/Input'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Toggle } from '@/components/atoms/Toggle/Toggle'
import { CategorySelect } from '@/components/catalog/CategorySelect'
import { colors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { UnitDropdown } from '@/features/uom/components/UnitDropdown'
import { EditorSectionCard } from '../editor/components/EditorSectionCard'
import type { ProductSectionsEditAdapter, ProductSectionsViewAdapter } from './types'

export type ProductGeneralAdapter =
  | Pick<ProductSectionsViewAdapter, 'mode' | 'product'>
  | Pick<ProductSectionsEditAdapter, 'mode' | 'form' | 'general'>

interface ProductGeneralSectionProps {
  adapter: ProductGeneralAdapter
}

interface ReadOnlyFieldProps {
  label: string
  value: string | null
  className?: string
  mono?: boolean
}

function ReadOnlyField({ label, value, className, mono = false }: ReadOnlyFieldProps) {
  return (
    <div className={className}>
      <div className={tokens.label.base}>{label}</div>
      <div className={cn('mt-1 text-sm', mono && 'font-mono', textColors.primary)}>
        {value === null || value === '' ? '\u2014' : value}
      </div>
    </div>
  )
}

export function ProductGeneralSection({ adapter }: ProductGeneralSectionProps) {
  const { t } = useTranslation(['catalog', 'inventory', 'common'])

  if (adapter.mode === 'view') {
    const { product } = adapter

    return (
      <EditorSectionCard
        id="section-general"
        title={t('catalog:editor.sectionLabels.general')}
      >
        <ReadOnlyField label={t('inventory:products.name')} value={product.name} />
        <ReadOnlyField label={t('inventory:products.sku')} value={product.sku} mono />
        <ReadOnlyField label={t('inventory:products.unitOfMeasure')} value={product.unit} />
        <ReadOnlyField label={t('inventory:products.category')} value={product.category?.name ?? null} />
        <ReadOnlyField
          className="sm:col-span-2"
          label={t('inventory:products.description')}
          value={product.description}
        />
        <div className="flex flex-wrap items-center gap-6 pt-1 sm:col-span-2">
          <span className={cn('text-sm font-medium', product.is_active ? textColors.success : textColors.tertiary)}>
            {product.is_active ? t('common:status.active') : t('common:status.inactive')}
          </span>
          {product.is_active_for_ecommerce && (
            <span className={cn('text-sm font-medium', textColors.brand)}>
              {t('inventory:products.isActiveForEcommerce')}
            </span>
          )}
        </div>
      </EditorSectionCard>
    )
  }

  const { control, errors, register, setValue, watch } = adapter.form
  const categoryId = watch('category_id')

  return (
    <EditorSectionCard
      id="section-general"
      title={t('catalog:editor.sectionLabels.general')}
    >
      <FormField
        label={`${t('inventory:products.name')} *`}
        htmlFor="name"
        error={errors.name?.message}
      >
        <Input
          type="text"
          id="name"
          error={Boolean(errors.name)}
          className={adapter.general.prefilledFields.has('name') ? colors.success[50] : ''}
          {...register('name', {
            required: t('inventory:products.nameRequired'),
            onChange: () => { adapter.general.clearPrefilledField('name') },
          })}
        />
      </FormField>

      <FormField
        label={`${t('inventory:products.sku')} *`}
        htmlFor="sku"
        error={errors.sku?.message}
      >
        <Input
          type="text"
          id="sku"
          error={Boolean(errors.sku)}
          {...register('sku', { required: t('inventory:products.skuRequired') })}
        />
      </FormField>

      <FormField label={t('inventory:products.unitOfMeasure')} htmlFor="unit_id">
        <UnitDropdown
          id="unit_id"
          value={watch('unit_id') ?? undefined}
          onChange={(unitId) => { setValue('unit_id', unitId || null) }}
        />
      </FormField>

      <FormField label={t('inventory:products.category')} htmlFor="category">
        <CategorySelect
          value={categoryId}
          onChange={(nextCategoryId) => { setValue('category_id', nextCategoryId) }}
          className="mt-1"
        />
      </FormField>

      <FormField
        className="sm:col-span-2"
        label={t('inventory:products.description')}
        htmlFor="description"
      >
        <Textarea
          id="description"
          rows={3}
          className={adapter.general.prefilledFields.has('description') ? colors.success[50] : ''}
          {...register('description', {
            onChange: () => { adapter.general.clearPrefilledField('description') },
          })}
        />
      </FormField>

      <div className="flex flex-wrap items-center gap-6 pt-1 sm:col-span-2">
        <Controller
          name="is_active"
          control={control}
          render={({ field }) => (
            <Toggle
              aria-label={t('inventory:products.active')}
              label={t('inventory:products.active')}
              checked={Boolean(field.value)}
              onChange={(event) => { field.onChange(event.target.checked) }}
              ref={field.ref}
            />
          )}
        />
        <Controller
          name="is_active_for_ecommerce"
          control={control}
          render={({ field }) => (
            <Toggle
              aria-label={t('inventory:products.isActiveForEcommerce')}
              label={t('inventory:products.isActiveForEcommerce')}
              checked={Boolean(field.value)}
              onChange={(event) => { field.onChange(event.target.checked) }}
              ref={field.ref}
            />
          )}
        />
      </div>
    </EditorSectionCard>
  )
}
