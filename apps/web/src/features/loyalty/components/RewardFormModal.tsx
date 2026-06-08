import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, Controller, useWatch, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input, FormField, Select, MoneyInput } from '@/components/atoms'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Modal } from '@/components/organisms/Modal/Modal'
import { useCompany } from '@/hooks/useCompany'
import type { Reward, CreateRewardData } from '../types/loyalty'

const schema = z.object({
  name: z.string().min(1),
  description: z.string().optional().nullable(),
  reward_type: z.enum(['free_item', 'discount_amount', 'discount_percent', 'choice', 'credit', 'external']),
  points_cost: z.string().min(1),
  reward_value: z.string().optional().nullable(),
  max_discount: z.string().optional().nullable(),
  min_order_value: z.string().optional().nullable(),
  quantity_available: z.coerce.number().int().positive().optional().nullable(),
  quantity_per_member: z.coerce.number().int().positive().optional().nullable(),
  start_date: z.string().optional().nullable(),
  end_date: z.string().optional().nullable(),
})

type FormValues = z.infer<typeof schema>

interface RewardFormModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (data: CreateRewardData) => void
  isPending: boolean
  editingReward: Reward | null
}

export function RewardFormModal({ isOpen, onClose, onSubmit, isPending, editingReward }: RewardFormModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const { currentCompany } = useCompany()
  const currency = currentCompany?.currency ?? 'EUR'

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      name: '',
      description: null,
      reward_type: 'discount_amount',
      points_cost: '',
      reward_value: null,
      max_discount: null,
      min_order_value: null,
      quantity_available: null,
      quantity_per_member: null,
      start_date: null,
      end_date: null,
    },
  })

  const rewardType = useWatch({ control: form.control, name: 'reward_type' })
  // reward_value is a monetary amount for all types except discount_percent
  const rewardValueIsPercent = rewardType === 'discount_percent'

  useEffect(() => {
    if (editingReward) {
      form.reset({
        name: editingReward.name,
        description: editingReward.description,
        reward_type: editingReward.reward_type,
        points_cost: editingReward.points_cost,
        reward_value: editingReward.reward_value,
        max_discount: editingReward.max_discount,
        min_order_value: editingReward.min_order_value,
        quantity_available: editingReward.quantity_available,
        quantity_per_member: editingReward.quantity_per_member,
        start_date: editingReward.start_date?.slice(0, 10) ?? null,
        end_date: editingReward.end_date?.slice(0, 10) ?? null,
      })
    } else {
      form.reset({
        name: '',
        description: null,
        reward_type: 'discount_amount',
        points_cost: '',
        reward_value: null,
        max_discount: null,
        min_order_value: null,
        quantity_available: null,
        quantity_per_member: null,
        start_date: null,
        end_date: null,
      })
    }
  }, [editingReward, form, isOpen])

  const handleSubmit = (values: FormValues) => {
    onSubmit({
      name: values.name,
      description: values.description || null,
      reward_type: values.reward_type,
      points_cost: values.points_cost,
      reward_value: values.reward_value || null,
      max_discount: values.max_discount || null,
      min_order_value: values.min_order_value || null,
      quantity_available: values.quantity_available ?? null,
      quantity_per_member: values.quantity_per_member ?? null,
      start_date: values.start_date || null,
      end_date: values.end_date || null,
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <Modal.Header
        title={editingReward ? t('loyalty:rewards.edit') : t('loyalty:rewards.create')}
        onClose={onClose}
      />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <div className="space-y-4">
            <FormField label={t('loyalty:fields.name')} error={form.formState.errors.name?.message}>
              <Input {...form.register('name')} />
            </FormField>

            <FormField label={t('loyalty:fields.description')}>
              <Textarea {...form.register('description')} rows={2} />
            </FormField>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.rewardType')}>
                <Select {...form.register('reward_type')}>
                  {(['free_item', 'discount_amount', 'discount_percent', 'choice', 'credit', 'external'] as const).map((type) => (
                    <option key={type} value={type}>{t(`loyalty:rewardTypes.${type}`)}</option>
                  ))}
                </Select>
              </FormField>

              <FormField label={t('loyalty:fields.pointsCost')} error={form.formState.errors.points_cost?.message}>
                <Input {...form.register('points_cost')} type="number" step="1" min="0" />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.rewardValue')}>
                {rewardValueIsPercent ? (
                  <Input {...form.register('reward_value')} type="number" step="0.01" min="0" max="100" />
                ) : (
                  <Controller
                    name="reward_value"
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
              <FormField label={t('loyalty:fields.maxDiscount')}>
                <Controller
                  name="max_discount"
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

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.minOrderValue')}>
                <Controller
                  name="min_order_value"
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
              <FormField label={t('loyalty:fields.quantityAvailable')}>
                <Input {...form.register('quantity_available')} type="number" min="1" />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.quantityPerMember')}>
                <Input {...form.register('quantity_per_member')} type="number" min="1" />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.startDate')}>
                <Input {...form.register('start_date')} type="date" />
              </FormField>
              <FormField label={t('loyalty:fields.endDate')}>
                <Input {...form.register('end_date')} type="date" />
              </FormField>
            </div>
          </div>
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isPending}>
            {isPending ? t('common:saving') : t('common:save')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
