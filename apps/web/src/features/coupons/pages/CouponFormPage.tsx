import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm, Controller, useWatch, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft } from 'lucide-react'
import { Button, Input, FormField, Select, MoneyInput } from '@/components/atoms'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { ProductSelector } from '@/features/products/components/ProductSelector'
import { CategorySelector } from '@/features/categories/components/CategorySelector'
import { useCompany } from '@/hooks/useCompany'

import { useCoupon, useCreateCoupon, useUpdateCoupon } from '../hooks/useCoupons'
import type { CreateCouponData } from '../api/couponApi'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

const couponSchema = z.object({
  name: z.string().min(1),
  code: z.string().min(1).max(50),
  type: z.enum(['standard', 'single_use', 'customer_specific']),
  discount_type: z.enum(['percentage', 'fixed']),
  discount_value: z.string().min(1),
  max_discount_amount: z.string().optional().nullable(),
  minimum_order_amount: z.string().optional().nullable(),
  is_single_use: z.boolean().optional(),
  max_uses: z.coerce.number().int().positive().optional().nullable(),
  max_uses_per_customer: z.coerce.number().int().positive().optional().nullable(),
  is_exclusive: z.boolean().optional(),
  stacking_group: z.string().optional(),
  starts_at: z.string().optional().nullable(),
  expires_at: z.string().optional().nullable(),
  qualifying_product_ids: z.array(z.string()).nullable().optional(),
  qualifying_category_ids: z.array(z.number().int().positive()).nullable().optional(),
})

type CouponFormValues = z.infer<typeof couponSchema>

export function CouponFormPage() {
  const { t } = useTranslation(['coupons', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const { currentCompany } = useCompany()
  const currency = currentCompany?.currency ?? 'EUR'
  const isEditing = !!id

  const { data: existingCoupon, isLoading: isLoadingCoupon } = useCoupon(id ?? '')
  const createMutation = useCreateCoupon()
  const updateMutation = useUpdateCoupon()

  const form = useForm<CouponFormValues>({
    resolver: zodResolver(couponSchema) as Resolver<CouponFormValues>,
    defaultValues: {
      name: '',
      code: '',
      type: 'standard',
      discount_type: 'percentage',
      discount_value: '',
      max_discount_amount: null,
      minimum_order_amount: null,
      is_single_use: false,
      max_uses: null,
      max_uses_per_customer: null,
      is_exclusive: false,
      stacking_group: 'coupons',
      starts_at: null,
      expires_at: null,
      qualifying_product_ids: null,
      qualifying_category_ids: null,
    },
  })

  const discountType = useWatch({ control: form.control, name: 'discount_type' })
  const discountValueIsPercent = discountType === 'percentage'

  useEffect(() => {
    if (existingCoupon) {
      form.reset({
        name: existingCoupon.name,
        code: existingCoupon.code,
        type: existingCoupon.type as CouponFormValues['type'],
        discount_type: existingCoupon.discount_type as CouponFormValues['discount_type'],
        discount_value: existingCoupon.discount_value,
        max_discount_amount: existingCoupon.max_discount_amount,
        minimum_order_amount: existingCoupon.minimum_order_amount,
        is_single_use: existingCoupon.is_single_use,
        max_uses: existingCoupon.max_uses,
        max_uses_per_customer: existingCoupon.max_uses_per_customer,
        is_exclusive: existingCoupon.is_exclusive,
        stacking_group: existingCoupon.stacking_group,
        starts_at: existingCoupon.starts_at?.slice(0, 16) ?? null,
        expires_at: existingCoupon.expires_at?.slice(0, 16) ?? null,
        qualifying_product_ids: existingCoupon.qualifying_product_ids ?? null,
        qualifying_category_ids: existingCoupon.qualifying_category_ids
          ? existingCoupon.qualifying_category_ids.map(Number)
          : null,
      })
    }
  }, [existingCoupon, form])

  const onSubmit = (values: CouponFormValues) => {
    const payload: CreateCouponData = {
      name: values.name,
      code: values.code,
      type: values.type,
      discount_type: values.discount_type,
      discount_value: values.discount_value,
      max_discount_amount: values.max_discount_amount || null,
      minimum_order_amount: values.minimum_order_amount || null,
      ...(values.is_single_use != null ? { is_single_use: values.is_single_use } : {}),
      max_uses: values.max_uses ?? null,
      max_uses_per_customer: values.max_uses_per_customer ?? null,
      ...(values.is_exclusive != null ? { is_exclusive: values.is_exclusive } : {}),
      ...(values.stacking_group ? { stacking_group: values.stacking_group } : {}),
      starts_at: values.starts_at || null,
      expires_at: values.expires_at || null,
      qualifying_product_ids: values.qualifying_product_ids?.length ? values.qualifying_product_ids : null,
      qualifying_category_ids: values.qualifying_category_ids?.length
        ? values.qualifying_category_ids.map(String)
        : null,
    }

    if (isEditing && id) {
      updateMutation.mutate(
        { id, data: payload },
        { onSuccess: () => navigate('/pos/coupons') },
      )
    } else {
      createMutation.mutate(payload, {
        onSuccess: () => navigate('/pos/coupons'),
      })
    }
  }

  if (isEditing && isLoadingCoupon) {
    return <div className={`text-center py-12 ${colorTokens.text.subtle}`}>{t('common:loading')}</div>
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  return (
    <div className="max-w-2xl space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={() => navigate('/pos/coupons')}
          className={`p-2 rounded-lg hover:${colorTokens.surface.muted}`}
        >
          <ArrowLeft className="w-5 h-5" />
        </button>
        <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>
          {isEditing ? t('coupons:editCoupon') : t('coupons:createCoupon')}
        </PageHeaderTitle>
      </div>

      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-8 pb-24">
        {/* Basic Info */}
        <section className="space-y-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('coupons:sections.basicInfo')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <FormField
              label={t('coupons:fields.name')}
              error={form.formState.errors.name?.message}
            >
              <Input {...form.register('name')} />
            </FormField>

            <FormField
              label={t('coupons:fields.code')}
              helperText={t('coupons:placeholders.codeHint')}
              error={form.formState.errors.code?.message}
            >
              <Input
                {...form.register('code')}
                placeholder={t('coupons:placeholders.code')}
                className="font-mono uppercase"
              />
            </FormField>
          </div>

          <FormField label={t('coupons:fields.type')}>
            <Select {...form.register('type')}>
              <option value="standard">{t('coupons:types.standard')}</option>
              <option value="single_use">{t('coupons:types.single_use')}</option>
              <option value="customer_specific">{t('coupons:types.customer_specific')}</option>
            </Select>
          </FormField>
        </section>

        {/* Discount Config */}
        <section className="space-y-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('coupons:sections.discountConfig')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('coupons:fields.discountType')}>
              <Select {...form.register('discount_type')}>
                <option value="percentage">{t('coupons:discountTypes.percentage')}</option>
                <option value="fixed">{t('coupons:discountTypes.fixed')}</option>
              </Select>
            </FormField>

            <FormField
              label={t('coupons:fields.discountValue')}
              error={form.formState.errors.discount_value?.message}
            >
              {discountValueIsPercent ? (
                <Input
                  {...form.register('discount_value')}
                  type="number"
                  step="0.01"
                  min="0"
                  max="100"
                />
              ) : (
                <Controller
                  name="discount_value"
                  control={form.control}
                  render={({ field }) => (
                    <MoneyInput
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      currency={currency}
                      min="0"
                    />
                  )}
                />
              )}
            </FormField>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('coupons:fields.maxDiscountAmount')}>
              <Controller
                name="max_discount_amount"
                control={form.control}
                render={({ field }) => (
                  <MoneyInput
                    value={field.value ?? ''}
                    onChange={field.onChange}
                    currency={currency}
                    min="0"
                  />
                )}
              />
            </FormField>

            <FormField label={t('coupons:fields.minimumOrderAmount')}>
              <Controller
                name="minimum_order_amount"
                control={form.control}
                render={({ field }) => (
                  <MoneyInput
                    value={field.value ?? ''}
                    onChange={field.onChange}
                    currency={currency}
                    min="0"
                  />
                )}
              />
            </FormField>
          </div>
        </section>

        {/* Usage Limits */}
        <section className="space-y-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('coupons:sections.usageLimits')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('coupons:fields.maxUses')}>
              <Input
                {...form.register('max_uses')}
                type="number"
                min="1"
              />
            </FormField>

            <FormField label={t('coupons:fields.maxUsesPerCustomer')}>
              <Input
                {...form.register('max_uses_per_customer')}
                type="number"
                min="1"
              />
            </FormField>
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              {...form.register('is_single_use')}
              className={`rounded ${colorTokens.border.default}`}
            />
            <label className={`text-sm ${colorTokens.text.secondary}`}>{t('coupons:fields.isSingleUse')}</label>
          </div>
        </section>

        {/* Targeting */}
        <section className="space-y-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('coupons:sections.targeting')}
          </h2>

          <Controller
            name="qualifying_product_ids"
            control={form.control}
            render={({ field }) => (
              <ProductSelector
                value={field.value ?? []}
                onChange={field.onChange}
                label={t('coupons:fields.qualifyingProducts')}
                helperText={t('coupons:helpers.qualifyingProducts')}
              />
            )}
          />

          <Controller
            name="qualifying_category_ids"
            control={form.control}
            render={({ field }) => (
              <CategorySelector
                value={field.value ?? []}
                onChange={field.onChange}
                label={t('coupons:fields.qualifyingCategories')}
                helperText={t('coupons:helpers.qualifyingCategories')}
              />
            )}
          />
        </section>

        {/* Schedule */}
        <section className="space-y-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('coupons:sections.schedule')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('coupons:fields.startsAt')}>
              <Input {...form.register('starts_at')} type="datetime-local" />
            </FormField>

            <FormField label={t('coupons:fields.expiresAt')}>
              <Input {...form.register('expires_at')} type="datetime-local" />
            </FormField>
          </div>
        </section>

        {/* Stacking */}
        <section className="space-y-4">
          <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
            {t('coupons:sections.stacking')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <FormField label={t('coupons:fields.stackingGroup')}>
              <Input {...form.register('stacking_group')} />
            </FormField>
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              {...form.register('is_exclusive')}
              className={`rounded ${colorTokens.border.default}`}
            />
            <label className={`text-sm ${colorTokens.text.secondary}`}>{t('coupons:fields.isExclusive')}</label>
          </div>
        </section>

        {/* Submit */}
        <StickyFormFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/pos/coupons')}
          >
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isSaving}>
            {isSaving ? t('common:saving') : isEditing ? t('common:save') : t('coupons:createCoupon')}
          </Button>
        </StickyFormFooter>
      </form>
    </div>
  )
}
