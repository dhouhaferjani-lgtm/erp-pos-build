import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, FormField, Textarea } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { VoidVoucherPayload } from '../types/voucher'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

const schema = z.object({
  reason: z.string().min(5, 'vouchers:validation.reasonMin'),
})

type FormValues = z.infer<typeof schema>

interface VoidVoucherModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (payload: VoidVoucherPayload) => void
  isPending: boolean
}

export function VoidVoucherModal({ isOpen, onClose, onSubmit, isPending }: VoidVoucherModalProps) {
  const { t } = useTranslation(['vouchers', 'common'])

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: { reason: '' },
  })

  const handleSubmit = (values: FormValues) => {
    onSubmit({ reason: values.reason })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm">
      <Modal.Header title={t('vouchers:void.title')} onClose={onClose} />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <p className={`text-sm ${colorTokens.text.secondary}`}>{t('vouchers:void.confirm')}</p>
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
          <Button type="submit" variant="danger" disabled={isPending}>
            {isPending ? t('common:saving') : t('vouchers:void.action')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
