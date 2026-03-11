import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input, FormField } from '@/components/atoms'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { AdjustPointsData } from '../types/loyalty'

const schema = z.object({
  points: z.string().min(1).refine((v) => !isNaN(Number(v)) && Number(v) !== 0, {
    message: 'Amount must be a non-zero number',
  }),
  reason: z.string().min(1),
})

type FormValues = z.infer<typeof schema>

interface AdjustPointsModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (data: AdjustPointsData) => void
  isPending: boolean
}

export function AdjustPointsModal({ isOpen, onClose, onSubmit, isPending }: AdjustPointsModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      points: '',
      reason: '',
    },
  })

  const handleSubmit = (values: FormValues) => {
    onSubmit({
      points: values.points,
      reason: values.reason,
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm">
      <Modal.Header title={t('loyalty:adjust.title')} onClose={onClose} />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <div className="space-y-4">
            <FormField
              label={t('loyalty:fields.amount')}
              helperText={t('loyalty:adjust.positiveForAdd')}
              error={form.formState.errors.points?.message}
            >
              <Input {...form.register('points')} type="number" step="1" />
            </FormField>

            <FormField
              label={t('loyalty:adjust.description')}
              error={form.formState.errors.reason?.message}
            >
              <Textarea {...form.register('reason')} rows={3} />
            </FormField>
          </div>
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isPending}>
            {isPending ? t('common:saving') : t('loyalty:actions.adjustPoints')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
