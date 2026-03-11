import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input, FormField, Select } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { Tier, CreateTierData } from '../types/loyalty'

const schema = z.object({
  name: z.string().min(1),
  level: z.coerce.number().int().min(1),
  qualification_type: z.enum(['spend', 'points_earned', 'visits', 'manual']),
  qualification_threshold: z.string().min(1),
  qualification_period_months: z.coerce.number().int().positive().optional().nullable(),
  earning_multiplier: z.string().optional(),
  color: z.string().optional().nullable(),
})

type FormValues = z.infer<typeof schema>

interface TierFormModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (data: CreateTierData) => void
  isPending: boolean
  editingTier: Tier | null
}

export function TierFormModal({ isOpen, onClose, onSubmit, isPending, editingTier }: TierFormModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      name: '',
      level: 1,
      qualification_type: 'spend',
      qualification_threshold: '',
      qualification_period_months: null,
      earning_multiplier: '1',
      color: null,
    },
  })

  useEffect(() => {
    if (editingTier) {
      form.reset({
        name: editingTier.name,
        level: editingTier.level,
        qualification_type: editingTier.qualification_type,
        qualification_threshold: editingTier.qualification_threshold,
        qualification_period_months: editingTier.qualification_period_months,
        earning_multiplier: editingTier.earning_multiplier,
        color: editingTier.color,
      })
    } else {
      form.reset({
        name: '',
        level: 1,
        qualification_type: 'spend',
        qualification_threshold: '',
        qualification_period_months: null,
        earning_multiplier: '1',
        color: null,
      })
    }
  }, [editingTier, form, isOpen])

  const handleSubmit = (values: FormValues) => {
    onSubmit({
      name: values.name,
      level: values.level,
      qualification_type: values.qualification_type,
      qualification_threshold: values.qualification_threshold,
      qualification_period_months: values.qualification_period_months ?? null,
      earning_multiplier: values.earning_multiplier || '1',
      color: values.color || null,
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <Modal.Header
        title={editingTier ? t('loyalty:tiers.edit') : t('loyalty:tiers.create')}
        onClose={onClose}
      />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.name')} error={form.formState.errors.name?.message}>
                <Input {...form.register('name')} />
              </FormField>
              <FormField label={t('loyalty:fields.level')}>
                <Input {...form.register('level')} type="number" min="1" />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.qualificationType')}>
                <Select {...form.register('qualification_type')}>
                  {(['spend', 'points_earned', 'visits', 'manual'] as const).map((type) => (
                    <option key={type} value={type}>{t(`loyalty:qualificationTypes.${type}`)}</option>
                  ))}
                </Select>
              </FormField>
              <FormField label={t('loyalty:fields.qualificationThreshold')} error={form.formState.errors.qualification_threshold?.message}>
                <Input {...form.register('qualification_threshold')} type="number" step="0.01" min="0" />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.qualificationPeriodMonths')}>
                <Input {...form.register('qualification_period_months')} type="number" min="1" />
              </FormField>
              <FormField label={t('loyalty:fields.earningMultiplier')}>
                <Input {...form.register('earning_multiplier')} type="number" step="0.01" min="0" />
              </FormField>
            </div>

            <FormField label="Color">
              <Input {...form.register('color')} type="color" className="h-10 w-20" />
            </FormField>
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
