import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input } from '@/components/atoms'
import { tokens } from '@/lib/designTokens'
import { useCoupon, useCreateCoupon, useUpdateCoupon } from '../hooks/useCoupons'
import type { CreateCouponData } from '../api/couponApi'

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
})

type CouponFormValues = z.infer<typeof couponSchema>

export function CouponFormPage() {
  const { t } = useTranslation(['coupons', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditing = !!id

  const { data: existingCoupon, isLoading: isLoadingCoupon } = useCoupon(id ?? '')
  const createMutation = useCreateCoupon()
  const updateMutation = useUpdateCoupon()

  const form = useForm<CouponFormValues>({
    resolver: zodResolver(couponSchema),
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
    },
  })

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
      })
    }
  }, [existingCoupon, form])

  const onSubmit = (values: CouponFormValues) => {
    const payload: CreateCouponData = {
      ...values,
      max_discount_amount: values.max_discount_amount || null,
      minimum_order_amount: values.minimum_order_amount || null,
      max_uses: values.max_uses ?? null,
      max_uses_per_customer: values.max_uses_per_customer ?? null,
      starts_at: values.starts_at || null,
      expires_at: values.expires_at || null,
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
    return <div className="text-center py-12 text-gray-500">{t('common:loading')}</div>
  }

  const isSaving = createMutation.isPending || updateMutation.isPending

  return (
    <div className="max-w-2xl space-y-6">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: tokens.colors.text.primary }}>
          {isEditing ? t('coupons:editCoupon') : t('coupons:createCoupon')}
        </h1>
      </div>

      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-8">
        {/* Basic Info */}
        <section className="space-y-4">
          <h2 className="text-lg font-semibold" style={{ color: tokens.colors.text.primary }}>
            {t('coupons:sections.basicInfo')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.name')}
              </label>
              <Input {...form.register('name')} />
              {form.formState.errors.name && (
                <p className="text-sm text-red-600 mt-1">{form.formState.errors.name.message}</p>
              )}
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.code')}
              </label>
              <Input
                {...form.register('code')}
                placeholder={t('coupons:placeholders.code')}
                className="font-mono uppercase"
              />
              <p className="text-xs text-gray-500 mt-1">{t('coupons:placeholders.codeHint')}</p>
              {form.formState.errors.code && (
                <p className="text-sm text-red-600 mt-1">{form.formState.errors.code.message}</p>
              )}
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {t('coupons:fields.type')}
            </label>
            <select
              {...form.register('type')}
              className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
            >
              <option value="standard">{t('coupons:types.standard')}</option>
              <option value="single_use">{t('coupons:types.single_use')}</option>
              <option value="customer_specific">{t('coupons:types.customer_specific')}</option>
            </select>
          </div>
        </section>

        {/* Discount Config */}
        <section className="space-y-4">
          <h2 className="text-lg font-semibold" style={{ color: tokens.colors.text.primary }}>
            {t('coupons:sections.discountConfig')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.discountType')}
              </label>
              <select
                {...form.register('discount_type')}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
              >
                <option value="percentage">{t('coupons:discountTypes.percentage')}</option>
                <option value="fixed">{t('coupons:discountTypes.fixed')}</option>
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.discountValue')}
              </label>
              <Input
                {...form.register('discount_value')}
                type="number"
                step="0.01"
                min="0"
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.maxDiscountAmount')}
              </label>
              <Input
                {...form.register('max_discount_amount')}
                type="number"
                step="0.01"
                min="0"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.minimumOrderAmount')}
              </label>
              <Input
                {...form.register('minimum_order_amount')}
                type="number"
                step="0.01"
                min="0"
              />
            </div>
          </div>
        </section>

        {/* Usage Limits */}
        <section className="space-y-4">
          <h2 className="text-lg font-semibold" style={{ color: tokens.colors.text.primary }}>
            {t('coupons:sections.usageLimits')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.maxUses')}
              </label>
              <Input
                {...form.register('max_uses')}
                type="number"
                min="1"
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.maxUsesPerCustomer')}
              </label>
              <Input
                {...form.register('max_uses_per_customer')}
                type="number"
                min="1"
              />
            </div>
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              {...form.register('is_single_use')}
              className="rounded border-gray-300"
            />
            <label className="text-sm text-gray-700">{t('coupons:fields.isSingleUse')}</label>
          </div>
        </section>

        {/* Schedule */}
        <section className="space-y-4">
          <h2 className="text-lg font-semibold" style={{ color: tokens.colors.text.primary }}>
            {t('coupons:sections.schedule')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.startsAt')}
              </label>
              <Input {...form.register('starts_at')} type="datetime-local" />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.expiresAt')}
              </label>
              <Input {...form.register('expires_at')} type="datetime-local" />
            </div>
          </div>
        </section>

        {/* Stacking */}
        <section className="space-y-4">
          <h2 className="text-lg font-semibold" style={{ color: tokens.colors.text.primary }}>
            {t('coupons:sections.stacking')}
          </h2>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('coupons:fields.stackingGroup')}
              </label>
              <Input {...form.register('stacking_group')} />
            </div>
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              {...form.register('is_exclusive')}
              className="rounded border-gray-300"
            />
            <label className="text-sm text-gray-700">{t('coupons:fields.isExclusive')}</label>
          </div>
        </section>

        {/* Actions */}
        <div className="flex items-center gap-3 pt-4 border-t">
          <Button type="submit" disabled={isSaving}>
            {isSaving ? t('common:saving') : isEditing ? t('common:save') : t('coupons:createCoupon')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/pos/coupons')}
          >
            {t('common:cancel')}
          </Button>
        </div>
      </form>
    </div>
  )
}
