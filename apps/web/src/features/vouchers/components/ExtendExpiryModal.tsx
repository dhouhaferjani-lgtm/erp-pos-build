import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input, FormField, Textarea } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { ExtendExpiryPayload } from '../types/voucher'

const schema = z.object({
  new_expires_at: z.string().min(1),
  reason: z.string().min(1),
})

type FormValues = z.infer<typeof schema>

interface ExtendExpiryModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (payload: ExtendExpiryPayload) => void
  isPending: boolean
}

export function ExtendExpiryModal({ isOpen, onClose, onSubmit, isPending }: ExtendExpiryModalProps) {
  const { t } = useTranslation(['vouchers', 'common'])

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: { new_expires_at: '', reason: '' },
  })

  const handleSubmit = (values: FormValues) => {
    onSubmit({ new_expires_at: values.new_expires_at, reason: values.reason })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm">
      <Modal.Header title={t('vouchers:extend.title')} onClose={onClose} />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <FormField
            label={t('vouchers:fields.newExpiresAt')}
            error={form.formState.errors.new_expires_at?.message}
          >
            <Input {...form.register('new_expires_at')} type="date" />
          </FormField>
          <FormField
            label={t('vouchers:fields.reason')}
            error={form.formState.errors.reason?.message}
          >
            <Textarea {...form.register('reason')} rows={3} />
          </FormField>
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isPending}>
            {isPending ? t('common:saving') : t('vouchers:extend.action')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
