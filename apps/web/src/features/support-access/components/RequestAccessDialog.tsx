import { useTranslation } from 'react-i18next'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Textarea } from '@/components/atoms/Textarea'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import { Modal } from '@/components/organisms/Modal'
import type { RequestAccessInput } from '../types'

interface Props {
  open: boolean
  busy: boolean
  onClose: () => void
  onSubmit: (input: RequestAccessInput) => Promise<unknown>
}

export function RequestAccessDialog({ open, busy, onClose, onSubmit }: Props) {
  const { t } = useTranslation('admin')
  const schema = z.object({
    tenant_id: z.uuid(t('supportAccess.validation.uuid')),
    subject_user_id: z.uuid(t('supportAccess.validation.uuid')),
    reason: z.string().trim().min(1, t('supportAccess.validation.required')),
    ticket_ref: z.string().trim().min(1, t('supportAccess.validation.required')),
    duration_minutes: z.number().int().min(1, t('supportAccess.validation.duration')).max(1440, t('supportAccess.validation.duration')),
  })
  const form = useForm<RequestAccessInput>({
    resolver: zodResolver(schema),
    defaultValues: {
      tenant_id: '',
      subject_user_id: '',
      reason: '',
      ticket_ref: '',
      duration_minutes: 60,
    },
  })

  const submit = async (values: RequestAccessInput) => {
    try {
      await onSubmit(values)
      form.reset()
      onClose()
    } catch {
      // Mutation hook owns the translated error toast; keep the form open.
    }
  }

  return (
    <Modal isOpen={open} onClose={onClose} title={t('supportAccess.dialog.title')} size="lg">
      <form onSubmit={(event) => { void form.handleSubmit(submit)(event) }}>
        <div className="mb-6">
          <p className={`text-xs font-semibold uppercase tracking-[0.18em] ${tokens.intent.primary.text}`}>{t('supportAccess.dialog.eyebrow')}</p>
          <p className={`mt-2 text-sm ${tokens.text.muted}`}>{t('supportAccess.dialog.description')}</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label={t('supportAccess.fields.tenantId')} htmlFor="support-request-tenant" required error={form.formState.errors.tenant_id?.message}>
            <Input id="support-request-tenant" {...form.register('tenant_id')} className="w-full" />
          </FormField>
          <FormField label={t('supportAccess.fields.subjectUserId')} htmlFor="support-request-subject" required error={form.formState.errors.subject_user_id?.message}>
            <Input id="support-request-subject" {...form.register('subject_user_id')} className="w-full" />
          </FormField>
          <FormField label={t('supportAccess.fields.reason')} htmlFor="support-request-reason" required error={form.formState.errors.reason?.message} className="sm:col-span-2">
            <Textarea id="support-request-reason" {...form.register('reason')} className="min-h-24 w-full" />
          </FormField>
          <FormField label={t('supportAccess.fields.ticket')} htmlFor="support-request-ticket" required error={form.formState.errors.ticket_ref?.message}>
            <Input id="support-request-ticket" {...form.register('ticket_ref')} className="w-full" />
          </FormField>
          <FormField label={t('supportAccess.fields.duration')} htmlFor="support-request-duration" required error={form.formState.errors.duration_minutes?.message}>
            <Input id="support-request-duration" min={1} max={1440} type="number" {...form.register('duration_minutes', { valueAsNumber: true })} className="w-full" />
          </FormField>
        </div>
        <div className="mt-6 flex justify-end gap-3">
          <Button type="button" variant="secondary" onClick={onClose}>{t('supportAccess.actions.cancel')}</Button>
          <Button type="submit" disabled={busy}>{t('supportAccess.actions.sendRequest')}</Button>
        </div>
      </form>
    </Modal>
  )
}
