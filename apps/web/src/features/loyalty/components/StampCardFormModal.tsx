import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input, FormField } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { StampCard, CreateStampCardData } from '../types/loyalty'

const schema = z.object({
  name: z.string().min(1),
  stamps_required: z.coerce.number().int().min(1),
  stamps_per_item: z.coerce.number().int().min(1),
  reward_id: z.string().min(1),
  max_active_cards: z.coerce.number().int().positive().optional().nullable(),
  expiry_days: z.coerce.number().int().positive().optional().nullable(),
})

type FormValues = z.infer<typeof schema>

interface StampCardFormModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (data: CreateStampCardData) => void
  isPending: boolean
  editingCard: StampCard | null
}

export function StampCardFormModal({ isOpen, onClose, onSubmit, isPending, editingCard }: StampCardFormModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      name: '',
      stamps_required: 10,
      stamps_per_item: 1,
      reward_id: '',
      max_active_cards: null,
      expiry_days: null,
    },
  })

  useEffect(() => {
    if (editingCard) {
      form.reset({
        name: editingCard.name,
        stamps_required: editingCard.stamps_required,
        stamps_per_item: editingCard.stamps_per_item,
        reward_id: editingCard.reward_id,
        max_active_cards: editingCard.max_active_cards,
        expiry_days: editingCard.expiry_days,
      })
    } else {
      form.reset({
        name: '',
        stamps_required: 10,
        stamps_per_item: 1,
        reward_id: '',
        max_active_cards: null,
        expiry_days: null,
      })
    }
  }, [editingCard, form, isOpen])

  const handleSubmit = (values: FormValues) => {
    onSubmit({
      name: values.name,
      stamps_required: values.stamps_required,
      stamps_per_item: values.stamps_per_item,
      reward_id: values.reward_id,
      max_active_cards: values.max_active_cards ?? null,
      expiry_days: values.expiry_days ?? null,
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="md">
      <Modal.Header
        title={editingCard ? t('loyalty:stampCards.edit') : t('loyalty:stampCards.create')}
        onClose={onClose}
      />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <div className="space-y-4">
            <FormField label={t('loyalty:fields.name')} error={form.formState.errors.name?.message}>
              <Input {...form.register('name')} />
            </FormField>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.stampsRequired')}>
                <Input {...form.register('stamps_required')} type="number" min="1" />
              </FormField>
              <FormField label={t('loyalty:fields.stampsPerItem')}>
                <Input {...form.register('stamps_per_item')} type="number" min="1" />
              </FormField>
            </div>

            <FormField label={t('loyalty:fields.rewardId')} error={form.formState.errors.reward_id?.message}>
              <Input {...form.register('reward_id')} placeholder="Reward UUID" />
            </FormField>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.maxActiveCards')}>
                <Input {...form.register('max_active_cards')} type="number" min="1" />
              </FormField>
              <FormField label={t('loyalty:fields.expiryDays')}>
                <Input {...form.register('expiry_days')} type="number" min="1" />
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
