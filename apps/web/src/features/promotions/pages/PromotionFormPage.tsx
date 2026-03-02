import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { Input, Textarea, FormField, Button, Select } from '@/components/atoms'
import { StickyFormFooter } from '@/components/molecules/StickyFormFooter/StickyFormFooter'
import { ProductSelector } from '@/features/products/components/ProductSelector'
import { CategorySelector } from '@/features/categories/components/CategorySelector'

import { usePromotion, useCreatePromotion, useUpdatePromotion } from '../hooks/usePromotions'
import type { CreatePromotionData, UpdatePromotionData } from '../api/promotionApi'

const PROMOTION_TYPES = ['happy_hour', 'buy_x_get_y', 'volume_discount', 'category_discount', 'combo_discount']
const DISCOUNT_TYPES = ['percentage', 'fixed', 'free_item']
const APPLIES_TO_OPTIONS = ['transaction', 'qualifying_items', 'specific_item', 'cheapest_item']
const DAYS = [1, 2, 3, 4, 5, 6, 7]

export function PromotionFormPage() {
  const { t } = useTranslation(['promotions', 'common'])
  const navigate = useNavigate()
  const { id } = useParams<{ id: string }>()
  const isEditing = !!id

  const { data: existingPromotion, isLoading: isLoadingPromotion } = usePromotion(id ?? '')
  const createMutation = useCreatePromotion()
  const updateMutation = useUpdatePromotion()

  // Form state
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [type, setType] = useState('happy_hour')
  const [priority, setPriority] = useState(0)
  const [isExclusive, setIsExclusive] = useState(false)
  const [stackingGroup, setStackingGroup] = useState('default')
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [daysOfWeek, setDaysOfWeek] = useState<number[]>([])
  const [timeFrom, setTimeFrom] = useState('')
  const [timeUntil, setTimeUntil] = useState('')
  const [discountType, setDiscountType] = useState('percentage')
  const [discountValue, setDiscountValue] = useState('')
  const [maxDiscountAmount, setMaxDiscountAmount] = useState('')
  const [appliesTo, setAppliesTo] = useState('transaction')
  const [usageLimit, setUsageLimit] = useState('')

  // Conditions state
  const [minQty, setMinQty] = useState('')
  const [minAmount, setMinAmount] = useState('')
  const [triggerQty, setTriggerQty] = useState('')
  const [categoryIds, setCategoryIds] = useState<number[]>([])
  const [qualifyingProductIds, setQualifyingProductIds] = useState<string[]>([])
  const [comboProductIds, setComboProductIds] = useState<string[]>([])

  // Populate form when editing
  useEffect(() => {
    if (existingPromotion) {
      const p = existingPromotion
      setName(p.name)
      setDescription(p.description ?? '')
      setType(p.type)
      setPriority(p.priority)
      setIsExclusive(p.is_exclusive)
      setStackingGroup(p.stacking_group)
      setStartsAt(p.starts_at ? p.starts_at.slice(0, 16) : '')
      setEndsAt(p.ends_at ? p.ends_at.slice(0, 16) : '')
      setDaysOfWeek(p.days_of_week ?? [])
      setTimeFrom(p.time_from ?? '')
      setTimeUntil(p.time_until ?? '')
      setDiscountType(p.discount_type)
      setDiscountValue(p.discount_value)
      setMaxDiscountAmount(p.max_discount_amount ?? '')
      setAppliesTo(p.applies_to)
      setUsageLimit(p.usage_limit !== null ? String(p.usage_limit) : '')

      const conditions = p.conditions as Record<string, unknown>
      setMinQty(conditions['min_qty'] ? String(conditions['min_qty']) : '')
      setMinAmount(conditions['min_amount'] ? String(conditions['min_amount']) : '')
      setTriggerQty(conditions['trigger_qty'] ? String(conditions['trigger_qty']) : '')
      setCategoryIds(Array.isArray(conditions['category_ids']) ? conditions['category_ids'] as number[] : [])
      setQualifyingProductIds(Array.isArray(conditions['qualifying_product_ids']) ? conditions['qualifying_product_ids'] as string[] : [])
      setComboProductIds(Array.isArray(conditions['combo_product_ids']) ? conditions['combo_product_ids'] as string[] : [])
    }
  }, [existingPromotion])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const conditions: Record<string, unknown> = {}
    if (minQty) conditions['min_qty'] = parseInt(minQty, 10)
    if (minAmount) conditions['min_amount'] = minAmount
    if (triggerQty) conditions['trigger_qty'] = parseInt(triggerQty, 10)
    if (type === 'category_discount' && categoryIds.length > 0) {
      conditions['category_ids'] = categoryIds
    }
    if (type === 'combo_discount' && comboProductIds.length > 0) {
      conditions['combo_product_ids'] = comboProductIds
    }
    if (['buy_x_get_y', 'volume_discount'].includes(type) && qualifyingProductIds.length > 0) {
      conditions['qualifying_product_ids'] = qualifyingProductIds
    }

    const payload: CreatePromotionData = {
      name,
      ...(description ? { description } : {}),
      type,
      priority,
      is_exclusive: isExclusive,
      stacking_group: stackingGroup,
      starts_at: startsAt || null,
      ends_at: endsAt || null,
      days_of_week: daysOfWeek.length > 0 ? daysOfWeek : null,
      time_from: timeFrom || null,
      time_until: timeUntil || null,
      discount_type: discountType,
      discount_value: discountValue,
      max_discount_amount: maxDiscountAmount || null,
      applies_to: appliesTo,
      usage_limit: usageLimit ? parseInt(usageLimit, 10) : null,
      conditions,
    }

    if (isEditing && id) {
      updateMutation.mutate(
        { id, data: payload as UpdatePromotionData },
        { onSuccess: () => navigate('/pos/promotions') },
      )
    } else {
      createMutation.mutate(payload, {
        onSuccess: () => navigate('/pos/promotions'),
      })
    }
  }

  const toggleDay = (day: number) => {
    setDaysOfWeek((prev) =>
      prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day].sort(),
    )
  }

  if (isEditing && isLoadingPromotion) {
    return <div className="text-center py-12 text-gray-500">{t('common:loading')}</div>
  }

  const isMutating = createMutation.isPending || updateMutation.isPending

  return (
    <form onSubmit={handleSubmit} className="space-y-8 pb-24">
      {/* Header */}
      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={() => navigate('/pos/promotions')}
          className="p-2 rounded-lg hover:bg-gray-100"
        >
          <ArrowLeft className="w-5 h-5" />
        </button>
        <h1 className="text-2xl font-bold text-gray-900">
          {isEditing ? t('promotions:editPromotion') : t('promotions:createPromotion')}
        </h1>
      </div>

      {/* Basic Info */}
      <section className="space-y-4 rounded-lg border border-gray-200 p-6">
        <h2 className="text-lg font-semibold">{t('common:details')}</h2>

        <FormField label={t('promotions:fields.name')} required>
          <Input value={name} onChange={(e) => setName(e.target.value)} required />
        </FormField>

        <FormField label={t('promotions:fields.description')}>
          <Textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={3} />
        </FormField>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('promotions:fields.type')} required>
            <Select
              value={type}
              onChange={(e) => setType(e.target.value)}
              required
            >
              {PROMOTION_TYPES.map((pt) => (
                <option key={pt} value={pt}>
                  {t(`promotions:types.${pt}`)}
                </option>
              ))}
            </Select>
          </FormField>

          <FormField label={t('promotions:fields.priority')}>
            <Input
              type="number"
              value={priority}
              onChange={(e) => setPriority(parseInt(e.target.value, 10) || 0)}
              min={0}
            />
          </FormField>
        </div>
      </section>

      {/* Discount Configuration */}
      <section className="space-y-4 rounded-lg border border-gray-200 p-6">
        <h2 className="text-lg font-semibold">{t('promotions:fields.discountType')}</h2>

        <div className="grid grid-cols-3 gap-4">
          <FormField label={t('promotions:fields.discountType')} required>
            <Select
              value={discountType}
              onChange={(e) => setDiscountType(e.target.value)}
              required
            >
              {DISCOUNT_TYPES.map((dt) => (
                <option key={dt} value={dt}>
                  {t(`promotions:discountTypes.${dt}`)}
                </option>
              ))}
            </Select>
          </FormField>

          <FormField label={t('promotions:fields.discountValue')} required>
            <Input
              type="number"
              value={discountValue}
              onChange={(e) => setDiscountValue(e.target.value)}
              step="0.01"
              min="0"
              required
            />
          </FormField>

          <FormField label={t('promotions:fields.maxDiscountAmount')}>
            <Input
              type="number"
              value={maxDiscountAmount}
              onChange={(e) => setMaxDiscountAmount(e.target.value)}
              step="0.01"
              min="0"
            />
          </FormField>
        </div>

        <FormField label={t('promotions:fields.appliesTo')} required>
          <Select
            value={appliesTo}
            onChange={(e) => setAppliesTo(e.target.value)}
            required
          >
            {APPLIES_TO_OPTIONS.map((opt) => (
              <option key={opt} value={opt}>
                {t(`promotions:appliesTo.${opt}`)}
              </option>
            ))}
          </Select>
        </FormField>
      </section>

      {/* Conditions */}
      <section className="space-y-4 rounded-lg border border-gray-200 p-6">
        <h2 className="text-lg font-semibold">{t('promotions:fields.conditions')}</h2>

        <div className="grid grid-cols-3 gap-4">
          {(type === 'volume_discount') && (
            <>
              <FormField label={t('promotions:fields.minimumQuantity')}>
                <Input
                  type="number"
                  value={minQty}
                  onChange={(e) => setMinQty(e.target.value)}
                  min="1"
                />
              </FormField>
              <FormField label={t('promotions:fields.minimumAmount')}>
                <Input
                  type="number"
                  value={minAmount}
                  onChange={(e) => setMinAmount(e.target.value)}
                  step="0.01"
                  min="0"
                />
              </FormField>
            </>
          )}
          {type === 'buy_x_get_y' && (
            <FormField label={t('promotions:fields.triggerQuantity')}>
              <Input
                type="number"
                value={triggerQty}
                onChange={(e) => setTriggerQty(e.target.value)}
                min="1"
              />
            </FormField>
          )}
        </div>

        {type === 'category_discount' && (
          <CategorySelector
            value={categoryIds}
            onChange={setCategoryIds}
            label={t('promotions:fields.categoryIds')}
            helperText={t('promotions:helpers.categoryIds')}
          />
        )}

        {type === 'combo_discount' && (
          <ProductSelector
            value={comboProductIds}
            onChange={setComboProductIds}
            label={t('promotions:fields.comboProducts')}
            helperText={t('promotions:helpers.comboProducts')}
          />
        )}

        {['buy_x_get_y', 'volume_discount'].includes(type) && (
          <ProductSelector
            value={qualifyingProductIds}
            onChange={setQualifyingProductIds}
            label={t('promotions:fields.qualifyingProducts')}
            helperText={t('promotions:helpers.qualifyingProducts')}
          />
        )}
      </section>

      {/* Schedule */}
      <section className="space-y-4 rounded-lg border border-gray-200 p-6">
        <h2 className="text-lg font-semibold">{t('promotions:schedule.title')}</h2>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('promotions:fields.startsAt')}>
            <Input
              type="datetime-local"
              value={startsAt}
              onChange={(e) => setStartsAt(e.target.value)}
            />
          </FormField>
          <FormField label={t('promotions:fields.endsAt')}>
            <Input
              type="datetime-local"
              value={endsAt}
              onChange={(e) => setEndsAt(e.target.value)}
            />
          </FormField>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <FormField label={t('promotions:fields.timeFrom')}>
            <Input
              type="time"
              value={timeFrom}
              onChange={(e) => setTimeFrom(e.target.value)}
            />
          </FormField>
          <FormField label={t('promotions:fields.timeUntil')}>
            <Input
              type="time"
              value={timeUntil}
              onChange={(e) => setTimeUntil(e.target.value)}
            />
          </FormField>
        </div>

        <FormField label={t('promotions:fields.daysOfWeek')}>
          <div className="flex gap-2">
            {DAYS.map((day) => (
              <button
                key={day}
                type="button"
                onClick={() => toggleDay(day)}
                className={`px-3 py-1.5 rounded-md text-sm font-medium transition-colors ${
                  daysOfWeek.includes(day)
                    ? 'bg-blue-500 text-white'
                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                }`}
              >
                {t(`promotions:days.${day}`)}
              </button>
            ))}
          </div>
        </FormField>
      </section>

      {/* Stacking & Limits */}
      <section className="space-y-4 rounded-lg border border-gray-200 p-6">
        <h2 className="text-lg font-semibold">{t('common:settings')}</h2>

        <div className="grid grid-cols-3 gap-4">
          <FormField label={t('promotions:fields.stackingGroup')}>
            <Input
              value={stackingGroup}
              onChange={(e) => setStackingGroup(e.target.value)}
            />
          </FormField>

          <FormField label={t('promotions:fields.usageLimit')}>
            <Input
              type="number"
              value={usageLimit}
              onChange={(e) => setUsageLimit(e.target.value)}
              min="1"
            />
          </FormField>

          <FormField label={t('promotions:fields.isExclusive')}>
            <label className="flex items-center gap-2 mt-2">
              <input
                type="checkbox"
                checked={isExclusive}
                onChange={(e) => setIsExclusive(e.target.checked)}
                className="rounded border-gray-300"
              />
              <span className="text-sm text-gray-700">{t('promotions:fields.isExclusive')}</span>
            </label>
          </FormField>
        </div>
      </section>

      {/* Submit */}
      <StickyFormFooter>
        <Button
          type="button"
          variant="secondary"
          onClick={() => navigate('/pos/promotions')}
        >
          {t('common:cancel')}
        </Button>
        <Button type="submit" disabled={isMutating}>
          {isMutating ? t('common:saving') : isEditing ? t('common:save') : t('common:create')}
        </Button>
      </StickyFormFooter>
    </form>
  )
}
