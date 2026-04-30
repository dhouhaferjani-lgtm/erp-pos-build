import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, FormField, Textarea } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import { PartnerPicker, type PartnerPickerValue } from '@/components/molecules/pickers/PartnerPicker'
import type { TransferVoucherPayload } from '../types/voucher'

const schema = z.object({
  reason: z.string().min(1),
})

type FormValues = z.infer<typeof schema>

interface TransferVoucherModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (payload: TransferVoucherPayload) => void
  isPending: boolean
}

export function TransferVoucherModal({ isOpen, onClose, onSubmit, isPending }: TransferVoucherModalProps) {
  const { t } = useTranslation(['vouchers', 'common'])
  const [toPartner, setToPartner] = useState<PartnerPickerValue | null>(null)
  const [partnerError, setPartnerError] = useState<string | null>(null)

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: { reason: '' },
  })

  const handleSubmit = (values: FormValues) => {
    if (!toPartner) {
      setPartnerError(t('vouchers:transfer.partnerRequired'))
      return
    }
    setPartnerError(null)
    onSubmit({ to_partner_id: toPartner.id, reason: values.reason })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm">
      <Modal.Header title={t('vouchers:transfer.title')} onClose={onClose} />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <FormField
            label={t('vouchers:transfer.toPartner')}
            error={partnerError ?? undefined}
          >
            <PartnerPicker
              value={toPartner}
              onChange={(p) => {
                setToPartner(p)
                if (p) setPartnerError(null)
              }}
              partnerType="customer"
            />
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
            {isPending ? t('common:saving') : t('vouchers:transfer.action')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
