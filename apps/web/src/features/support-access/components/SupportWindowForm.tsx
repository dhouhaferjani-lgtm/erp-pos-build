import { useTranslation } from 'react-i18next'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Textarea } from '@/components/atoms/Textarea'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import type { CreateSupportWindowInput } from '../types'

interface Props { busy: boolean; onSubmit: (input: CreateSupportWindowInput) => Promise<unknown> }
interface SupportWindowFormValues {
  subject_user_id: string
  reason: string
  ticket_ref: string
  starts_at: string
  expires_at: string
}

function localDate(minutesFromNow: number): string {
  const date = new Date(Date.now() + minutesFromNow * 60_000)
  return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16)
}

export function SupportWindowForm({ busy, onSubmit }: Props) {
  const { t } = useTranslation('support-access')
  const schema = z.object({
    subject_user_id: z.string(),
    reason: z.string().trim().min(1, t('validation.required')),
    ticket_ref: z.string().trim().min(1, t('validation.required')),
    starts_at: z.string().min(1, t('validation.required')),
    expires_at: z.string().min(1, t('validation.required')),
  }).refine(
    (values) => Date.parse(values.expires_at) > Date.parse(values.starts_at),
    { path: ['expires_at'], message: t('validation.expiryAfterStart') },
  )
  const form = useForm<SupportWindowFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      subject_user_id: '',
      reason: '',
      ticket_ref: '',
      starts_at: localDate(5),
      expires_at: localDate(65),
    },
  })

  const submit = async (values: SupportWindowFormValues) => {
    await onSubmit({
      ...(values.subject_user_id ? { subject_user_id: values.subject_user_id } : {}),
      reason: values.reason,
      ticket_ref: values.ticket_ref,
      starts_at: new Date(values.starts_at).toISOString(),
      expires_at: new Date(values.expires_at).toISOString(),
    })
  }

  return (
    <form onSubmit={(event) => { void form.handleSubmit(submit)(event) }} className={`rounded-2xl border p-5 ${tokens.surface.base} ${tokens.border.subtle}`}>
      <h2 className={`text-lg font-semibold ${tokens.text.primary}`}>{t('window.title')}</h2>
      <p className={`mt-1 text-sm ${tokens.text.muted}`}>{t('window.description')}</p>
      <div className="mt-5 grid gap-4 md:grid-cols-2">
        <FormField label={t('fields.subjectOptional')} htmlFor="support-window-subject">
          <Input id="support-window-subject" {...form.register('subject_user_id')} className="w-full" />
        </FormField>
        <FormField label={t('fields.ticket')} htmlFor="support-window-ticket" required error={form.formState.errors.ticket_ref?.message}>
          <Input id="support-window-ticket" {...form.register('ticket_ref')} className="w-full" />
        </FormField>
        <FormField label={t('fields.reason')} htmlFor="support-window-reason" required error={form.formState.errors.reason?.message} className="md:col-span-2">
          <Textarea id="support-window-reason" {...form.register('reason')} className="min-h-24 w-full" />
        </FormField>
        <FormField label={t('fields.startsAt')} htmlFor="support-window-starts" required error={form.formState.errors.starts_at?.message}>
          <Input id="support-window-starts" type="datetime-local" {...form.register('starts_at')} className="w-full" />
        </FormField>
        <FormField label={t('fields.expiresAt')} htmlFor="support-window-expires" required error={form.formState.errors.expires_at?.message}>
          <Input id="support-window-expires" type="datetime-local" {...form.register('expires_at')} className="w-full" />
        </FormField>
      </div>
      <Button type="submit" disabled={busy} className="mt-5">{t('actions.createWindow')}</Button>
    </form>
  )
}
